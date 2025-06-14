<?php
// Docker MySQL/MariaDB Connection
$servername = "mariadb"; // Docker service name
$username = "root";
$password = "passroot"; // From your environment variables
$dbname = "nextcloud_db"; // From your environment variables

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) die("Erreur de connexion DB: " . $conn->connect_error);

// Configuration Proxmox avec token - Update these values for your environment
$proxmox_host = "https://192.168.56.102:8006";
$node = "pve";
$storage = "local";

$proxmox_token_id = "root@pam!VM";
$proxmox_token_secret = "aa582c7c-b0bf-4b1b-ac89-09bfb55c44cb";
$url = "$proxmox_host/api2/json/nodes/$node/storage/$storage/content";
$data = [];

// Appel API Proxmox avec token
$curl = curl_init($url);
curl_setopt_array($curl, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        "Authorization: PVEAPIToken=$proxmox_token_id=$proxmox_token_secret"
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYSTATUS => false,
]);
$response = curl_exec($curl);
if ($response === false) {
    die("Erreur API Proxmox : " . curl_error($curl));
}
$data = json_decode($response, true);
curl_close($curl);

// Traitement formulaire
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $etudiant = trim($_POST['etudiant'] ?? '');
    $modele = trim($_POST['modele'] ?? '');
    $matiere = trim($_POST['matiere'] ?? '');

    if (empty($etudiant) || empty($modele) || empty($matiere)) {
        $message = "<p class='error'>Tous les champs sont requis.</p>";
    } else {
        // Récupérer nom_tag depuis la table matieres_tags
        $stmt = $conn->prepare("SELECT nom_tag FROM matieres_tags WHERE matiere = ?");
        $stmt->bind_param("s", $matiere);
        $stmt->execute();
        $stmt->bind_result($nom_tag);
        $stmt->fetch();
        $stmt->close();

if ($nom_tag) {
            // Extraction plus souple des dates (avec espaces possibles)
            preg_match('/DateDebut:\s*(\d{2}\/\d{2}\/\d{4})/', $nom_tag, $matches_debut);
            preg_match('/DateFin:\s*(\d{2}\/\d{2}\/\d{4})/', $nom_tag, $matches_fin);

            if (!empty($matches_debut[1]) && !empty($matches_fin[1])) {
                $date_debut = DateTime::createFromFormat('d/m/Y', $matches_debut[1])->format('Y-m-d');
                $date_fin = DateTime::createFromFormat('d/m/Y', $matches_fin[1])->format('Y-m-d');

                // Insérer la demande dans demandes_lxc
                $stmt = $conn->prepare("INSERT INTO demandes_lxc (etudiant, modele, statut, matiere, date_debut, date_fin) VALUES (?, ?, 'En attente', ?, ?, ?)");
                $stmt->bind_param("sssss", $etudiant, $modele, $matiere, $date_debut, $date_fin);
                if ($stmt->execute()) {
                    $message = "<p class='success'>Demande enregistrée avec succès. Fin prévue : $date_fin</p>";
                } else {
                    $message = "<p class='error'>Erreur SQL : " . htmlspecialchars($stmt->error) . "</p>";
                }
                $stmt->close();
            } else {
                $message = "<p class='error'>Erreur : Dates mal formatées dans la matière sélectionnée.</p>";
            }
        } else {
            $message = "<p class='error'>Matière inconnue ou dates non définies.</p>";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Demande de Conteneur LXC</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f0f4f8;
            padding: 40px;
            display: flex;
            justify-content: center;
        }
        .container {
            background: white;
            padding: 30px 40px;
            border-radius: 12px;
            box-shadow: 0 6px 18px rgba(0,0,0,0.1);
            max-width: 450px;
            width: 100%;
        }
        h2 {
            color: #0072c6;
            margin-bottom: 20px;
            text-align: center;
        }
        label {
            font-weight: 600;
            display: block;
            margin-top: 15px;
            margin-bottom: 6px;
        }
        input, select, button {
            width: 100%;
            padding: 10px 12px;
            font-size: 14px;
            border: 1px solid #ccc;
            border-radius: 8px;
            transition: border-color 0.3s ease;
        }
        input:focus, select:focus {
            border-color: #0072c6;
            outline: none;
        }
        button {
            margin-top: 25px;
            background-color: #0072c6;
            color: white;
            font-weight: bold;
            cursor: pointer;
            border: none;
        }
        button:hover {
            background-color: #005fa3;
        }
        .success {
            background-color: #d4edda;
            color: #155724;
            padding: 10px;
            border-radius: 8px;
            margin-bottom: 15px;
            text-align: center;
            font-weight: 600;
        }
        .error {
            background-color: #f8d7da;
            color: #721c24;
            padding: 10px;
            border-radius: 8px;
            margin-bottom: 15px;
            text-align: center;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>Formulaire de demande de conteneur LXC</h2>
        <?= $message ?? '' ?>

        <form method="POST" novalidate>
            <label for="etudiant">Nom de l'étudiant :</label>
            <input type="text" id="etudiant" name="etudiant" required value="<?= htmlspecialchars($_POST['etudiant'] ?? '') ?>">

            <label for="modele">Choisir un modèle :</label>
            <select id="modele" name="modele" required>
                <?php if (isset($data['data']) && is_array($data['data'])): ?>
                    <?php foreach ($data['data'] as $item): ?>
                        <?php if ($item['content'] === "vztmpl"): ?>
                            <?php $val = htmlspecialchars(basename($item['volid'])); ?>
                            <option value="<?= $val ?>" <?= (isset($_POST['modele']) && $_POST['modele'] === $val) ? 'selected' : '' ?>>
                                <?= $val ?>
                            </option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php else: ?>
                    <option disabled>Aucun modèle disponible</option>
                <?php endif; ?>
            </select>

            <label for="matiere">Choisir la matière :</label>
            <select id="matiere" name="matiere" required>
                <?php
                $result = $conn->query("SELECT matiere FROM matieres_tags ORDER BY matiere ASC");
                while ($row = $result->fetch_assoc()) {
                    $safe_matiere = htmlspecialchars($row['matiere']);
                    $selected = (isset($_POST['matiere']) && $_POST['matiere'] === $row['matiere']) ? 'selected' : '';
                    echo "<option value=\"$safe_matiere\" $selected>$safe_matiere</option>";
                }
                ?>
            </select>

            <button type="submit">Envoyer</button>
        </form>
    </div>
</body>
</html>

<?php
$conn->close();
?>
