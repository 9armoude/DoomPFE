<?php
// Docker MySQL/MariaDB Connection
$servername = "mariadb"; // Docker service name
$username = "root";
$password = "passroot"; // From your environment variables
$dbname = "nextcloud_db"; // From your environment variables

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) die("Erreur de connexion DB: " . $conn->connect_error);

$proxmox_host = "https://192.168.56.102:8006";
$node = "VM";
$storage = "local";

$proxmox_token_id = "root@pam!VM";
$proxmox_token_secret = "aa582c7c-b0bf-4b1b-ac89-09bfb55c44cb";

$url = "$proxmox_host/api2/json/nodes/$node/storage/$storage/content";
$data = [];

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

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $etudiant = trim($_POST['etudiant'] ?? '');
    $modele = trim($_POST['modele'] ?? '');
    $matiere = trim($_POST['matiere'] ?? '');

    if (empty($etudiant) || empty($modele) || empty($matiere)) {
        $message = "<p style='color: red;'>Tous les champs sont requis.</p>";
    } else {
        $stmt = $conn->prepare("SELECT nom_tag FROM matieres_tags WHERE matiere = ?");
        $stmt->bind_param("s", $matiere);
        $stmt->execute();
        $stmt->bind_result($nom_tag);
        $stmt->fetch();
        $stmt->close();

        if ($nom_tag) {
            // Expressions régulières plus souples (espaces après les deux-points)
            preg_match('/DateDebut:\s*(\d{2}\/\d{2}\/\d{4})/', $nom_tag, $matches_debut);
            preg_match('/DateFin:\s*(\d{2}\/\d{2}\/\d{4})/', $nom_tag, $matches_fin);

            // Debug facultatif
            /*
            echo "<pre>";
            echo "nom_tag: $nom_tag\n";
            print_r($matches_debut);
            print_r($matches_fin);
            echo "</pre>";
            */

            if (!empty($matches_debut[1]) && !empty($matches_fin[1])) {
                $date_debut = DateTime::createFromFormat('d/m/Y', trim($matches_debut[1]))->format('Y-m-d');
                $date_fin = DateTime::createFromFormat('d/m/Y', trim($matches_fin[1]))->format('Y-m-d');

                $stmt = $conn->prepare("INSERT INTO demandes_vm (etudiant, modele, statut, matiere, date_debut, date_fin) VALUES (?, ?, 'En attente', ?, ?, ?)");
                $stmt->bind_param("sssss", $etudiant, $modele, $matiere, $date_debut, $date_fin);
                if ($stmt->execute()) {
                    $message = "<p style='color: green;'>Demande enregistrée avec succès. Fin prévue : $date_fin</p>";
                } else {
                    $message = "<p style='color: red;'>Erreur SQL : " . $stmt->error . "</p>";
                }
                $stmt->close();
            } else {
                $message = "<p style='color: red;'>Erreur : Dates mal formatées dans la matière sélectionnée.</p>";
            }
        } else {
            $message = "<p style='color: red;'>Matière inconnue ou dates non définies.</p>";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Demande de Machine Virtuelle</title>
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', sans-serif;
            background: linear-gradient(135deg, #dceeff, #e8f4ff);
            margin: 0;
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100vh;
        }

        .container {
            background-color: #ffffff;
            padding: 40px;
            border-radius: 16px;
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.1);
            max-width: 650px;
            width: 90%;
        }

        h2 {
            color: #005fa3;
            text-align: center;
            margin-bottom: 30px;
            font-size: 24px;
        }

        label {
            font-weight: 600;
            display: block;
            margin-bottom: 6px;
            color: #333;
        }

        input, select {
            width: 100%;
            padding: 12px;
            margin-bottom: 20px;
            border: 1px solid #ccc;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.2s;
        }

        input:focus, select:focus {
            border-color: #0072c6;
            outline: none;
        }

        button {
            width: 100%;
            padding: 14px;
            background-color: #0072c6;
            color: white;
            font-weight: bold;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            cursor: pointer;
            transition: background-color 0.3s ease;
        }

        button:hover {
            background-color: #005fa3;
        }

        .success, .error {
            padding: 12px;
            margin-bottom: 20px;
            border-radius: 8px;
            text-align: center;
            font-weight: bold;
        }

        .success {
            background-color: #e0f9e8;
            color: #2e7d32;
            border: 1px solid #b2dfdb;
        }

        .error {
            background-color: #ffe0e0;
            color: #c62828;
            border: 1px solid #f5c6cb;
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>Formulaire de demande de VM</h2>
        <?= $message ?? '' ?>

        <form method="POST">
            <label for="etudiant">Nom de l'étudiant :</label>
            <input type="text" id="etudiant" name="etudiant" required>

            <label for="modele">Choisir un modèle :</label>
            <select name="modele" id="modele" required>
                <?php if (isset($data['data'])): ?>
                    <?php foreach ($data['data'] as $item): ?>
                        <?php if ($item['content'] === "iso"): ?>
                            <option value="<?= htmlspecialchars(basename($item['volid'])) ?>">
                                <?= htmlspecialchars(basename($item['volid'])) ?>
                            </option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php else: ?>
                    <option disabled>Aucun modèle disponible</option>
                <?php endif; ?>
            </select>

            <label for="matiere">Choisir la matière :</label>
            <select name="matiere" id="matiere" required>
                <?php
                $result = $conn->query("SELECT matiere FROM matieres_tags ORDER BY matiere ASC");
                while ($row = $result->fetch_assoc()) {
                    $safe_matiere = htmlspecialchars($row['matiere'], ENT_QUOTES, 'UTF-8');
                    echo "<option value=\"$safe_matiere\">$safe_matiere</option>";
                }
                ?>
            </select>

            <button type="submit">Envoyer</button>
        </form>
    </div>
</body>
</html> 

<?php $conn->close(); ?>
