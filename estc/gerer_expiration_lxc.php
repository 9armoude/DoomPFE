<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Load environment variables (if using a .env file)
// Alternatively, you can hardcode the values or use Docker environment variables
$db_host = "mariadb"; // Docker service name
$db_user = "root";
$db_password = "passroot"; // From your .env file
$nextcloud_db = "nextcloud_db";
$guacamole_db = "guacamole_db";

// Connexion à la base de données Nextcloud
$conn = new mysqli($db_host, $db_user, $db_password, $nextcloud_db);
if ($conn->connect_error) {
    die("Erreur connexion DB: " . $conn->connect_error);
}

// Connexion à la base de données Guacamole
$guac_conn = new mysqli($db_host, $db_user, $db_password, $guacamole_db);
if ($guac_conn->connect_error) {
    die("Erreur connexion DB Guacamole: " . $guac_conn->connect_error);
}

$action = $_POST['action'] ?? '';
$id = isset($_POST['id']) ? intval($_POST['id']) : null;

if (in_array($action, ['valider', 'refuser', 'supprimer']) && $id !== null) {
    $query = "SELECT * FROM demandes_lxc WHERE id = $id";
    $result = $conn->query($query);

    if ($result->num_rows === 1) {
        $row = $result->fetch_assoc();
        $etudiant = $row['etudiant'];
        $matiere = $row['matiere'];
        $ctid = 100 + $id;
        $nom_connexion = "lxc-" . $etudiant . "-" . $id;

        if ($action === 'valider') {
            $message = "📢 Votre conteneur pour $matiere a expiré. Voulez-vous le conserver ?";
            // Note: This command will need to be adapted for Docker environment
            // You might need to exec into the nextcloud container or use docker exec
            $cmd = "docker exec nextcloud_app sudo -u www-data /usr/bin/php /var/www/html/occ notification:generate $etudiant \"$message\"";
            exec($cmd . " 2>&1", $output, $code);

            if ($code === 0) {
                echo "✅ Notification envoyée à $etudiant";
                $conn->query("UPDATE demandes_lxc SET action_admin = 'valide' WHERE id = $id");
            } else {
                echo "❌ Erreur notification (code: $code)";
                echo "<br>Output: " . implode("\n", $output);
            }

        } elseif ($action === 'refuser') {
            $conn->query("UPDATE demandes_lxc SET action_admin = 'refuse' WHERE id = $id");
            echo "🚫 L'étudiant souhaite conserver le conteneur.";

        } elseif ($action === 'supprimer') {
            // Note: LXC container management will need to be adapted for Docker environment
            // This might involve Docker API calls or custom scripts
            $wrapper_cmd = "sudo /usr/local/bin/wrapper_supprimer_lxc.sh $ctid";
            exec($wrapper_cmd . " 2>&1", $output, $code);

            if ($code === 0) {
                echo "🗑 Conteneur $ctid supprimé avec succès.";

                // Suppression dans Guacamole
                $stmt = $guac_conn->prepare("DELETE FROM guacamole_connection WHERE connection_name = ?");
                $stmt->bind_param("s", $nom_connexion);
                $stmt->execute();

                if ($stmt->affected_rows > 0) {
                    echo " ✅ Connexion Guacamole supprimée.";
                } else {
                    echo " ⚠ Aucune connexion Guacamole trouvée pour $nom_connexion.";
                }
                $stmt->close();

                $conn->query("UPDATE demandes_lxc SET statut = 'Supprime', action_admin = 'valide' WHERE id = $id");
            } else {
                echo "❌ Échec suppression conteneur (code: $code)";
                echo "<br>Output: " . implode("\n", $output);
            }
        }
    } else {
        echo "❌ Demande non trouvée.";
    }

} elseif (in_array($action, ['valider_tout', 'supprimer_tout'])) {
    $query = "SELECT * FROM demandes_lxc 
              WHERE date_fin < CURDATE() 
              AND statut = 'Valide'
              ORDER BY action_admin ASC, reponse_etudiant ASC";
    $result = $conn->query($query);

    if ($result->num_rows > 0) {
        $nbTraites = 0;

        while ($row = $result->fetch_assoc()) {
            $id = $row['id'];
            $etudiant = $row['etudiant'];
            $matiere = $row['matiere'];
            $ctid = 100 + $id;
            $nom_connexion = "lxc-" . $etudiant . "-" . $id;

            if ($action === 'valider_tout') {
                $message = "📢 Votre conteneur pour $matiere a expiré. Voulez-vous le conserver ?";
                $cmd = "docker exec nextcloud_app sudo -u www-data /usr/bin/php /var/www/html/occ notification:generate $etudiant \"$message\"";
                exec($cmd . " 2>&1", $output, $code);

                if ($code === 0) {
                    $conn->query("UPDATE demandes_lxc SET action_admin = 'valide' WHERE id = $id");
                    $nbTraites++;
                }

            } elseif ($action === 'supprimer_tout') {
                $wrapper_cmd = "sudo /usr/local/bin/wrapper_supprimer_lxc.sh $ctid";
                exec($wrapper_cmd . " 2>&1", $output, $code);

                if ($code === 0) {
                    // Supprimer la connexion Guacamole
                    $stmt = $guac_conn->prepare("DELETE FROM guacamole_connection WHERE connection_name = ?");
                    $stmt->bind_param("s", $nom_connexion);
                    $stmt->execute();
                    $stmt->close();

                    $conn->query("UPDATE demandes_lxc SET statut = 'Supprime', action_admin = 'valide' WHERE id = $id");
                    $nbTraites++;
                }
            }
        }

        if ($nbTraites > 0) {
            echo ($action === 'valider_tout') 
                ? "✅ Notifications envoyées pour $nbTraites conteneurs expirés." 
                : "🗑 $nbTraites conteneurs supprimés avec succès.";
        } else {
            echo "⚠ Aucun conteneur traité. Peut-être déjà notifiés ou supprimés.";
        }

    } else {
        echo "🎉 Aucun conteneur expiré à traiter.";
    }

} else {
    echo "❌ Action non reconnue.";
}

$conn->close();
$guac_conn->close();
?>
