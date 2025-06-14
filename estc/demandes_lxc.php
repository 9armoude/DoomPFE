<?php
/* page validation d accepation de demande et clonage des templates aux etudiants */

// Connexion à la base de données Nextcloud (MariaDB dans Docker)
$conn = new mysqli("mariadb", "root", "passroot", "nextcloud_db");
if ($conn->connect_error) {
    die("<p style='color: red;'>Erreur de connexion : " . htmlspecialchars($conn->connect_error) . "</p>");
}

// Connexion à la base Guacamole (même conteneur MariaDB)
$conn_guacamole = new mysqli("mariadb", "root", "passroot", "guacamole_db");
if ($conn_guacamole->connect_error) {
    die("<p style='color: red;'>Erreur connexion Guacamole : " . $conn_guacamole->connect_error . "</p>");
}

session_start();
$message = $_SESSION['message'] ?? '';
unset($_SESSION['message']);

// Paramètres Proxmox (à adapter selon votre configuration)
$proxmox_host = "https://192.168.56.102:8006";
$node = "pve";
$storage = "local-lvm";

$proxmox_token_id = "root@pam!VM";
$proxmox_token_secret = "aa582c7c-b0bf-4b1b-ac89-09bfb55c44cb";

// Générer une IP unique (sans /24)
function generateUniqueIP($base_ip, $offset) {
    $parts = explode('.', $base_ip);
    if (count($parts) !== 4) return false;
    $last_octet = (int)$parts[3] + $offset;
    if ($last_octet > 254) $last_octet = 254;
    return "{$parts[0]}.{$parts[1]}.{$parts[2]}.$last_octet";
}

// Cloner un conteneur
function cloneContainer($source_ctid, $new_ctid, $hostname, $proxmox_host, $node, $storage, $token_id, $token_secret) {
    $url = "$proxmox_host/api2/json/nodes/$node/lxc/$source_ctid/clone";
    $post_data = [
        "newid" => $new_ctid,
        "hostname" => $hostname,
        "full" => 1,
        "storage" => $storage
    ];
    return callProxmoxAPI("POST", $url, $post_data, $token_id, $token_secret);
}

// Configurer le réseau du conteneur
function setContainerNetwork($ctid, $net0, $proxmox_host, $node, $token_id, $token_secret) {
    $url = "$proxmox_host/api2/json/nodes/$node/lxc/$ctid/config";
    return callProxmoxAPI("PUT", $url, ['net0' => $net0], $token_id, $token_secret);
}

// Démarrer un conteneur
function startContainer($ctid, $proxmox_host, $node, $token_id, $token_secret) {
    $url = "$proxmox_host/api2/json/nodes/$node/lxc/$ctid/status/start";
    return callProxmoxAPI("POST", $url, [], $token_id, $token_secret);
}

// Fonction API générique
function callProxmoxAPI($method, $url, $data, $token_id, $token_secret) {
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_POSTFIELDS => http_build_query($data),
        CURLOPT_HTTPHEADER => [
            "Authorization: PVEAPIToken $token_id=$token_secret",
            "Content-Type: application/x-www-form-urlencoded"
        ],
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $response = curl_exec($curl);
    $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    return [$http_code, $response, $error];
}

// Traitement POST
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (isset($_POST['accept'])) {
        $id = intval($_POST['accept']);
        $stmt = $conn->prepare("UPDATE demandes_lxc SET statut = 'Approuv' WHERE id = ?");
        $stmt->bind_param("i", $id);
        $_SESSION['message'] = $stmt->execute() ?
            "<p style='color: green;'>Demande acceptée.</p>" :
            "<p style='color: red;'>Erreur d'acceptation.</p>";
        $stmt->close();
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }

    if (isset($_POST['deploy'])) {
        $id = intval($_POST['deploy']);

        $stmt = $conn->prepare("SELECT * FROM demandes_lxc WHERE id = ? AND statut = 'Approuv'");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $demande = $result->fetch_assoc();
        $stmt->close();

        if ($demande) {
            $etudiant = $demande['etudiant'];
            $modele = $demande['modele'];
            $ctid = 100 + $id;
            $hostname = "lxc-" . strtolower(preg_replace('/[^a-z0-9]/', '', $etudiant)) . "-$id";

            // Génération IP unique
            $ip = generateUniqueIP("10.0.2.100", $ctid);

            $source_ctid = match ($modele) {
                "debian-12-standard_12.7-1_amd64.tar.zst" => 108,
                "ubuntu-24.04-standard_24.04-2_amd64.tar.zst" => 106,
                default => null
            };

            if (!$source_ctid) {
                $_SESSION['message'] = "<p style='color: red;'>Modèle inconnu.</p>";
                header("Location: " . $_SERVER['PHP_SELF']);
                exit;
            }

            // Clonage
            [$http_code, $resp, $err] = cloneContainer($source_ctid, $ctid, $hostname, $proxmox_host, $node, $storage, $proxmox_token_id, $proxmox_token_secret);
            if ($err) {
                $_SESSION['message'] = "<p style='color: red;'>Erreur clonage : $err</p>";
                header("Location: " . $_SERVER['PHP_SELF']);
                exit;
            }

            sleep(15); // Attente après le clonage

            // Configuration réseau
            $net0 = "name=eth0,bridge=vmbr0,ip={$ip}/24,gw=10.0.2.2";
            [$net_code, $net_resp, $net_err] = setContainerNetwork($ctid, $net0, $proxmox_host, $node, $proxmox_token_id, $proxmox_token_secret);
            if ($net_err || $net_code != 200) {
                $_SESSION['message'] = "<p style='color: red;'>Erreur config réseau : $net_err - $net_resp</p>";
                header("Location: " . $_SERVER['PHP_SELF']);
                exit;
            }

            // ✅ Démarrage automatique du conteneur
            [$start_code, $start_resp, $start_err] = startContainer($ctid, $proxmox_host, $node, $proxmox_token_id, $proxmox_token_secret);
            if ($start_err || $start_code != 200) {
                $_SESSION['message'] = "<p style='color: red;'>Erreur démarrage : $start_err - $start_resp</p>";
                header("Location: " . $_SERVER['PHP_SELF']);
                exit;
            }

            // Mise à jour BDD
            $stmt = $conn->prepare("UPDATE demandes_lxc SET statut = 'Valide', ip_conteneur = ?, hostname = ?  WHERE id = ?");
            $stmt->bind_param("ssi", $ip, $hostname, $id);
            $stmt->execute();
            $stmt->close();

            // Connexion Guacamole
            $nom_connexion = $hostname;
            $protocol = "ssh";
            $port = 22;
            $username_guac = "root";
            $password_guac = "proxmoxo";

            $insert_connexion = $conn_guacamole->prepare("
                INSERT INTO guacamole_connection (connection_name, protocol, max_connections, max_connections_per_user)
                VALUES (?, ?, 0, 0)
            ");
            $insert_connexion->bind_param("ss", $nom_connexion, $protocol);
            $insert_connexion->execute();
            $connexion_id = $conn_guacamole->insert_id;
            $insert_connexion->close();

            if ($connexion_id) {
                $params = [
                    'hostname' => $ip,
                    'port' => $port,
                    'username' => $username_guac,
                    'password' => $password_guac
                ];
                foreach ($params as $name => $value) {
                    $insert_param = $conn_guacamole->prepare("
                        INSERT INTO guacamole_connection_parameter (connection_id, parameter_name, parameter_value)
                        VALUES (?, ?, ?)
                    ");
                    $insert_param->bind_param("iss", $connexion_id, $name, $value);
                    $insert_param->execute();
                    $insert_param->close();
                }
            }

// 3. Donner les permissions à guacadmin et à l'étudiant
$users = ['guacadmin', $etudiant];

foreach ($users as $user) {
    // Récupérer l'entity_id de l'utilisateur Guacamole
    $stmt_entity = $conn_guacamole->prepare("
        SELECT entity_id FROM guacamole_entity WHERE name = ? AND type = 'USER'
    ");
    $stmt_entity->bind_param("s", $user);
    $stmt_entity->execute();
    $result_entity = $stmt_entity->get_result();
    $entity = $result_entity->fetch_assoc();
    $stmt_entity->close();

    if ($entity && isset($entity['entity_id'])) {
        $entity_id = $entity['entity_id'];

        $insert_perm = $conn_guacamole->prepare("
            INSERT INTO guacamole_connection_permission (entity_id, connection_id, permission)
            VALUES (?, ?, 'READ')
        ");
        $insert_perm->bind_param("ii", $entity_id, $connexion_id);
        $insert_perm->execute();
        $insert_perm->close();
    } else {
        // Optionnel : journaliser l'erreur si l'utilisateur Guacamole n'existe pas
        error_log("Utilisateur '$user' introuvable dans guacamole_entity");
    }
}

            $_SESSION['message'] = "<p style='color: green;'>Conteneur déployé, IP : $ip (démarré).</p>";
        } else {
            $_SESSION['message'] = "<p style='color: red;'>Demande non approuvée.</p>";
        }

        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
}

// Vérification conteneurs supprimés
$demandes = $conn->query("SELECT * FROM demandes_lxc WHERE statut = 'Valide'");
while ($row = $demandes->fetch_assoc()) {
    $ctid = 100 + $row['id'];
    $check_url = "$proxmox_host/api2/json/nodes/$node/lxc/$ctid/status/current";

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $check_url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: PVEAPIToken $proxmox_token_id=$proxmox_token_secret"
        ],
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_SSL_VERIFYPEER => false
    ]);

    $response = curl_exec($curl);
    $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($http_code !== 200) {
        $update_query = $conn->prepare("UPDATE demandes_lxc SET statut = 'Supprime' WHERE id = ?");
        $update_query->bind_param("i", $row['id']);
        $update_query->execute();
        $update_query->close();
    }
}

// Affichage de la liste
$demandes = $conn->query("SELECT * FROM demandes_lxc WHERE statut != 'Supprime' ORDER BY id ASC");
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Gestion des Demandes LXC</title>
    <style>
        /* Reset et base */
        body {
            font-family: "Ubuntu", "Helvetica Neue", Helvetica, Arial, sans-serif;
            background-color: #f6f8fa;
            margin: 0;
            padding: 20px;
            color: #333;
        }
        h2 {
            color: #0078d4; /* Bleu Nextcloud */
            text-align: center;
            margin-bottom: 30px;
            font-weight: 600;
        }
        /* Message (erreur / succès) */
        p {
            padding: 10px 15px;
            border-radius: 4px;
            max-width: 600px;
            margin: 0 auto 25px auto;
            font-size: 1rem;
        }
        p[style*="green"] {
            background-color: #daf3da;
            color: #206620;
            border: 1px solid #206620;
        }
        p[style*="red"] {
            background-color: #f8d7da;
            color: #842029;
            border: 1px solid #842029;
        }

        /* Table */
        table {
            width: 100%;
            max-width: 900px;
            margin: 0 auto;
            border-collapse: collapse;
            background-color: white;
            box-shadow: 0 2px 8px rgb(0 0 0 / 0.1);
            border-radius: 6px;
            overflow: hidden;
        }
        th, td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #e1e4e8;
            font-size: 0.95rem;
        }
        th {
            background-color: #0078d4; /* Bleu Nextcloud */
            color: white;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        tr:hover {
            background-color: #e6f0fb;
        }
        td:last-child {
            text-align: center;
        }

        /* Boutons */
        button {
            background-color: #0078d4;
            border: none;
            color: white;
            padding: 7px 14px;
            font-size: 0.9rem;
            border-radius: 4px;
            cursor: pointer;
            transition: background-color 0.2s ease;
        }
        button:hover {
            background-color: #005a9e;
        }
        form {
            display: inline;
            margin: 0 5px;
        }
.badge-success {
    display: inline-block;
    background-color: #d4edda;
    color: #155724;
    padding: 5px 10px;
    border-radius: 20px;
    font-size: 0.85rem;
    font-weight: 500;
    border: 1px solid #c3e6cb;
}

    </style>
</head>
<body>
    <h2>Gestion des Demandes LXC</h2>
    <?= $message ?>

    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Étudiant</th>
                <th>Modèle</th>
                <th>Statut</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
        <?php $i = 1; while ($row = $demandes->fetch_assoc()): ?>
            <tr>
                <td><?= $i++ ?></td>
                <td><?= htmlspecialchars($row['etudiant']) ?></td>
                <td><?= htmlspecialchars($row['modele']) ?></td>
                <td><?= htmlspecialchars($row['statut']) ?></td>
                <td>
                    <?php if ($row['statut'] === 'En attente'): ?>
                        <form method="POST" style="display:inline;">
                            <button type="submit" name="accept" value="<?= $row['id'] ?>">Accepter</button>
                        </form>
                    <?php elseif ($row['statut'] === 'Approuv'): ?>
                        <form method="POST" style="display:inline;">
                            <button type="submit" name="deploy" value="<?= $row['id'] ?>">Déployer</button>
                        </form>
                    <?php elseif ($row['statut'] === 'Valide'): ?>
        <span class="badge-success">Déployé avec succès</span>
    <?php else: ?>
        <?= htmlspecialchars($row['statut']) ?>
    <?php endif; ?>
                </td>
            </tr>
        <?php endwhile; ?>
        </tbody>
    </table>
</body>
</html>
