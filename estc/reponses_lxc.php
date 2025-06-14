<?php
// 🔐 Intégration de l'environnement Nextcloud
require_once '/var/www/html/lib/base.php'; // Path within the Nextcloud container

// Vérification de l'authentification Nextcloud
$nc_user = \OC_User::getUser(); 
if (!$nc_user) {
    die("<p style='color: red;'>Erreur : utilisateur non connecté à Nextcloud.</p>");
}

$nom_etudiant = $nc_user;
$message = "";

// Database connection parameters for Docker environment
$db_host = "mariadb"; // Docker service name
$db_user = "root";
$db_password = "passroot"; // From your .env file
$nextcloud_db = "nextcloud_db";

// Connexion à la base de données
$conn = new mysqli($db_host, $db_user, $db_password, $nextcloud_db);
if ($conn->connect_error) {
    die("❌ Erreur de connexion à la base de données : " . $conn->connect_error);
}

// Traitement de la réponse Oui / Non
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $id = intval($_POST['demande_id']);
    $reponse = $_POST['reponse'];

    // Vérifier si une réponse a déjà été donnée pour cette demande
    $check_stmt = $conn->prepare("SELECT reponse_etudiant FROM demandes_lxc WHERE id = ? AND etudiant = ?");
    $check_stmt->bind_param("is", $id, $nom_etudiant);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        $row = $check_result->fetch_assoc();
        if ($row['reponse_etudiant'] !== 'en_attente') {
            $message = "❌ Réponse déjà envoyée pour cette demande.";
        } else {
            // Mettre à jour la réponse dans la base de données
            $stmt = $conn->prepare("UPDATE demandes_lxc SET reponse_etudiant = ? WHERE id = ? AND etudiant = ?");
            if ($stmt === false) {
                $message = "❌ Erreur de préparation de la requête UPDATE : " . $conn->error;
            } else {
                $stmt->bind_param("sis", $reponse, $id, $nom_etudiant);
                $stmt->execute();
                
                if ($stmt->affected_rows > 0) {
                    $message = "✅ Votre réponse a été enregistrée pour la demande ID $id.";
                } else {
                    $message = "❌ Erreur lors de l'enregistrement de votre réponse. Aucun changement détecté.";
                }
                $stmt->close();
            }
        }
    } else {
        $message = "❌ Aucune demande trouvée avec cet ID pour l'étudiant $nom_etudiant.";
    }
    $check_stmt->close();
}

// Récupérer les demandes "Valide" de cet étudiant
$sql = "SELECT id, modele, statut, date_demande, reponse_etudiant FROM demandes_lxc 
        WHERE etudiant = ? AND statut = 'Valide' AND reponse_etudiant = 'en_attente'";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $nom_etudiant);
$stmt->execute();
$result = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Demandes de <?php echo htmlspecialchars($nom_etudiant); ?></title>
   <style>
    body {
        font-family: "Segoe UI", "Helvetica Neue", sans-serif;
        padding: 30px;
        background-color: #f0f3f8;
        color: #333;
    }
    h2 {
        color: #0062cc;
    }
    .demande {
        background-color: #ffffff;
        margin-bottom: 20px;
        padding: 20px;
        border-radius: 10px;
        border-left: 5px solid #0082c9;
        box-shadow: 0 2px 6px rgba(0, 0, 0, 0.05);
    }
    form {
        display: inline;
    }
    button {
        padding: 10px 20px;
        margin: 5px;
        border: none;
        border-radius: 5px;
        color: white;
        font-weight: bold;
        cursor: pointer;
        transition: background-color 0.3s ease;
    }
    .oui {
        background-color: #0082c9;
    }
    .oui:hover {
        background-color: #006fad;
    }
    .non {
        background-color: #d9534f;
    }
    .non:hover {
        background-color: #c9302c;
    }
    .message {
        color: #0062cc;
        font-weight: bold;
        background-color: #d9edf7;
        padding: 10px;
        border: 1px solid #bce8f1;
        border-radius: 5px;
        margin-bottom: 20px;
    }
    strong {
        color: #005ea6;
    }
</style>

</head>
<body>

<h2>Demandes valides de <?php echo htmlspecialchars($nom_etudiant); ?></h2>

<?php if (!empty($message)): ?>
    <p class="message"><?php echo $message; ?></p>
<?php endif; ?>

<?php while ($row = $result->fetch_assoc()): ?>
    <div class="demande">
        <p><strong>Demande pour la machine :</strong> <?php echo htmlspecialchars($row['modele']); ?></p>
        <p><strong>Statut :</strong> <?php echo htmlspecialchars($row['statut']); ?></p>
        <p><strong>Date de la demande :</strong> <?php echo htmlspecialchars($row['date_demande']); ?></p>

        <?php if ($row['reponse_etudiant'] === 'en_attente'): ?>
            <p><strong>Voulez-vous garder la machine ?</strong></p>
            <form method="post">
                <input type="hidden" name="demande_id" value="<?php echo $row['id']; ?>">
                <button class="oui" type="submit" name="reponse" value="oui">Oui</button>
                <button class="non" type="submit" name="reponse" value="non">Non</button>
            </form>
        <?php else: ?>
            <p><em>Réponse déjà envoyée : <?php echo htmlspecialchars($row['reponse_etudiant']); ?></em></p>
        <?php endif; ?>
    </div>
<?php endwhile; ?>

</body>
</html>
