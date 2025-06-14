<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Connexion à la base de données (MariaDB dans Docker)
$conn = new mysqli("mariadb", "root", "passroot", "nextcloud_db");
if ($conn->connect_error) {
    die("Erreur connexion DB: " . $conn->connect_error);
}

// Récupérer tous les conteneurs expirés dont le statut est valide
$query = "SELECT * FROM demandes_lxc 
          WHERE date_fin < CURDATE() 
          AND statut = 'Valide'
          ORDER BY action_admin ASC, reponse_etudiant ASC";

$result = $conn->query($query);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Conteneurs expirés</title>
    <style>
        /* Base */
        body {
            font-family: "Ubuntu", "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f0f3f5;
            color: #2d2d2d;
            padding: 30px;
            margin: 0;
        }

        h2 {
            color: #0078d4; /* Bleu Nextcloud */
            font-weight: 600;
            margin-bottom: 20px;
        }

        /* Conteneur des boutons globaux */
        .action-global {
            margin-bottom: 25px; /* espace sous les boutons */
            display: flex;
            gap: 12px; /* espace entre les boutons */
        }

        /* Tableau */
        table {
            border-collapse: collapse;
            width: 100%;
            background: white;
            box-shadow: 0 2px 5px rgb(0 0 0 / 0.1);
            border-radius: 8px;
            overflow: hidden;
        }

        th, td {
            padding: 12px 15px;
            text-align: center;
            border-bottom: 1px solid #e1e4e8;
            font-size: 14px;
        }

        th {
            background-color: #0078d4;
            color: white;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        tr:last-child td {
            border-bottom: none;
        }

        tr:hover {
            background-color: #e6f0fa;
        }

        /* Boutons */
        form.inline-form {
            display: inline-flex;
            gap: 8px;
        }

        .btn {
            padding: 8px 14px;
            border: none;
            border-radius: 5px;
            font-weight: 600;
            font-size: 13px;
            cursor: pointer;
            transition: background-color 0.3s ease;
            color: white;
            box-shadow: 0 2px 4px rgb(0 120 212 / 0.3);
        }

        .valider {
            background-color: #0078d4;
        }
        .valider:hover {
            background-color: #005a9e;
            box-shadow: 0 3px 6px rgb(0 90 158 / 0.5);
        }

        .refuser {
            background-color: #d9534f;
            box-shadow: 0 2px 4px rgb(217 83 79 / 0.3);
        }
        .refuser:hover {
            background-color: #b52b27;
            box-shadow: 0 3px 6px rgb(181 43 39 / 0.5);
        }

        .supprimer {
            background-color: #f0ad4e;
            box-shadow: 0 2px 4px rgb(240 173 78 / 0.3);
            color: #3e3e3e;
        }
        .supprimer:hover {
            background-color: #d48811;
            color: white;
            box-shadow: 0 3px 6px rgb(212 136 17 / 0.5);
        }

        /* Messages */
        p {
            font-size: 15px;
        }
    </style>
</head>
<body>
    <h2>🔔 Liste des conteneurs expirés</h2>

    <div class="action-global">
        <form method="POST" action="gerer_expiration_lxc.php" class="inline-form">
            <input type="hidden" name="action" value="valider_tout">
            <button class="btn valider">✅ Valider tout</button>
        </form>
        <form method="POST" action="gerer_expiration_lxc.php" class="inline-form">
            <input type="hidden" name="action" value="supprimer_tout">
            <button class="btn supprimer">🗑 Supprimer tout</button>
        </form>
    </div>

    <?php if ($result->num_rows > 0): ?>
        <table>
            <tr>
                <th>ID</th>
                <th>Étudiant</th>
                <th>Modèle</th>
                <th>Matière</th>
                <th>IP</th>
                <th>Date fin</th>
                <th>Réponse Étudiant</th>
                <th>Décision Admin</th>
                <th>Actions</th>
            </tr>
            <?php while($row = $result->fetch_assoc()): ?>
                <tr>
                    <td><?= htmlspecialchars($row['id']) ?></td>
                    <td><?= htmlspecialchars($row['etudiant']) ?></td>
                    <td><?= htmlspecialchars($row['modele']) ?></td>
                    <td><?= htmlspecialchars($row['matiere']) ?></td>
                    <td><?= htmlspecialchars($row['ip_conteneur']) ?></td>
                    <td><?= htmlspecialchars($row['date_fin']) ?></td>
                    <td>
                        <?php
                            if ($row['reponse_etudiant'] == 'oui') {
                                echo "✅ Oui";
                            } elseif ($row['reponse_etudiant'] == 'non') {
                                echo "❌ Non";
                            } else {
                                echo "⏳ En attente";
                            }
                        ?>
                    </td>
                    <td>
                        <?php
                            if ($row['action_admin'] == 'valide') {
                                echo "✅ Validé";
                            } elseif ($row['action_admin'] == 'refuse') {
                                echo "❌ Refusé";
                            } else {
                                echo "⏳ En attente";
                            }
                        ?>
                    </td>
                    <td>
                        <form method="POST" action="gerer_expiration_lxc.php" class="inline-form">
                            <input type="hidden" name="id" value="<?= htmlspecialchars($row['id']) ?>">
                            <button class="btn valider" name="action" value="valider">✅ Valider</button>
                            <button class="btn refuser" name="action" value="refuser">❌ Refuser</button>
                            <button class="btn supprimer" name="action" value="supprimer">🗑 Supprimer</button>
                        </form>
                    </td>
                </tr>
            <?php endwhile; ?>
        </table>
    <?php else: ?>
        <p>Aucun conteneur expiré pour le moment 🎉</p>
    <?php endif; ?>

</body>
</html>

<?php
$conn->close();
?>
