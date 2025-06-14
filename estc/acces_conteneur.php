<?php
// Connection to Nextcloud database
$conn = new mysqli("mariadb", "root", "passroot", "nextcloud_db");
if ($conn->connect_error) {
    die("<p style='color: red;'>Erreur de connexion : " . $conn->connect_error . "</p>");
}

// Connection to Guacamole database
$conn_guacamole = new mysqli("mariadb", "root", "passroot", "guacamole_db");
if ($conn_guacamole->connect_error) {
    die("<p style='color: red;'>Erreur connexion Guacamole : " . $conn_guacamole->connect_error . "</p>");
}

$demandes = $conn->query("
    SELECT *, ROW_NUMBER() OVER (ORDER BY id ASC) AS num_affichage 
    FROM demandes_lxc 
    WHERE statut != 'Supprime'
");
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Accès aux Conteneurs</title>
    <style>
        body {
            font-family: "Open Sans", sans-serif;
            background-color: #f0f4f8;
            color: #2c3e50;
            padding: 20px;
        }

        h2 {
            color: #0082c9;
            text-align: center;
        }

        table {
            width: 80%;
            border-collapse: collapse;
            background-color: #fff;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            border-radius: 8px;
            overflow: hidden;
            max-width: 1200px;
            margin: 0 auto;
        }

        th, td {
            padding: 12px 15px;
            text-align: left;
        }

        th {
            background-color: #0082c9;
            color: white;
        }

        tr:nth-child(even) {
            background-color: #f9f9f9;
        }

        button {
            background-color: #0082c9;
            color: white;
            border: none;
            padding: 8px 12px;
            border-radius: 5px;
            cursor: pointer;
        }

        button:hover {
            background-color: #006da4;
        }

        a {
            text-decoration: none;
        }
    </style>
</head>
<body>
    <h2>Liste des Conteneurs</h2>
    <table>
        <tr>
            <th>#</th>
            <th>Étudiant</th>
            <th>Modèle</th>
            <th>Statut</th>
            <th>Adresse IP</th>
            <th>Hostname</th>
            <th>Accès</th>
        </tr>

        <?php while ($row = $demandes->fetch_assoc()): ?>
            <?php
            if (empty($row['id_guacamole']) && !empty($row['hostname'])) {
                $hostname = $conn_guacamole->real_escape_string($row['hostname']);
                $query_guac = $conn_guacamole->query("
                    SELECT connection_id 
                    FROM guacamole_connection 
                    WHERE connection_name = '$hostname'
                    LIMIT 1
                ");

                if ($guac = $query_guac->fetch_assoc()) {
                    $id_guacamole = $guac['connection_id'];
                    $id = (int)$row['id'];
                    $conn->query("UPDATE demandes_lxc SET id_guacamole = $id_guacamole WHERE id = $id");
                    $row['id_guacamole'] = $id_guacamole;
                } else {
                    $row['id_guacamole'] = null;
                }
            }
            ?>

            <tr>
                <td><?= $row['num_affichage'] ?></td>
                <td><?= htmlspecialchars($row['etudiant']) ?></td>
                <td><?= htmlspecialchars($row['modele']) ?></td>
                <td><?= htmlspecialchars($row['statut']) ?></td>
                <td><?= htmlspecialchars($row['ip_conteneur'] ?: 'Non attribuée') ?></td>
                <td><?= htmlspecialchars($row['hostname'] ?: 'Non défini') ?></td>
                <td>
                    <?php if ($row['statut'] == 'En attente'): ?>
                        ⏳ Accès en attente
                    <?php elseif ($row['statut'] == 'Approuv'): ?>
                        ⚙ Accès en cours
                    <?php elseif ($row['statut'] == 'Valide'): ?>
                        <?php if (!empty($row['ip_conteneur']) && !empty($row['id_guacamole']) && is_numeric($row['id_guacamole'])): ?>
                            <?php
                                $to_encode = $row['id_guacamole'] . "\x00" . "c" . "\x00" . "mysql";
                                $encoded_id = base64_encode($to_encode);
                            ?>
                            <a href="http://192.168.56.102:8080/guacamole/#/client/<?= htmlspecialchars($encoded_id) ?>" target="_blank">
                                <button>🚀 Accéder au Conteneur</button>
                            </a>
                        <?php else: ?>
                            ⛔ Conteneur actif mais accès Guacamole inconnu
                        <?php endif; ?>
                    <?php else: ?>
                        ❌ Statut inconnu
                    <?php endif; ?>
                </td>
            </tr>
        <?php endwhile; ?>
    </table>
</body>
</html>
