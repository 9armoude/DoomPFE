<?php 
echo '
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8" />
    <title>Liste des Modèles LXC - Proxmox</title>
    <style>
        body {
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f6f8fa;
            margin: 0; padding: 0;
            color: #2c3e50;
        }
        .container {
            max-width: 1000px;
            margin: 30px auto;
            padding: 30px;
            background-color: #fff;
            border-radius: 8px;
            box-shadow: 0 0 15px rgba(0,0,0,0.05);
        }
        h2 {
            font-size: 22px;
            margin-bottom: 25px;
            color: #2c3e50;
            text-align: left;
            border-bottom: 2px solid #d7dde5;
            padding-bottom: 10px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            padding: 12px 15px;
            border-bottom: 1px solid #d7dde5;
            text-align: left;
        }
        th {
            background-color: #e9f0f7;
            color: #005a9e;
            text-transform: uppercase;
        }
        tr:hover {
            background-color: #dbe9fb;
        }
        .error {
            color: #d9534f;
            font-weight: bold;
            margin: 20px 0;
            text-align: center;
        }
    </style>
</head>
<body>

    <div class="container">
        <h2>📦 Modèles LXC disponibles</h2>
        <table>
            <thead>
                <tr>
                    <th>Nom du modèle</th>
                </tr>
            </thead>
            <tbody>';
            
$proxmox_host = "https://192.168.56.102:8006";
$node = "pve";
$storage = "local";

$proxmox_token_id = "root@pam!VM";
$proxmox_token_secret = "aa582c7c-b0bf-4b1b-ac89-09bfb55c44cb";

$url = "$proxmox_host/api2/json/nodes/$node/storage/$storage/content?content=vztmpl";

$curl = curl_init();
curl_setopt_array($curl, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        "Authorization: PVEAPIToken=$proxmox_token_id=$proxmox_token_secret"
    ],
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_SSL_VERIFYPEER => false,
]);

$response = curl_exec($curl);
$http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);

if ($response === false) {
    echo '<tr><td class="error">Erreur cURL : ' . curl_error($curl) . '</td></tr>';
    curl_close($curl);
    echo '</tbody></table></div></body></html>';
    exit();
}
curl_close($curl);

if ($http_code !== 200) {
    echo "<tr><td class='error'>Erreur HTTP $http_code : Impossible de récupérer les modèles.</td></tr>";
    echo '</tbody></table></div></body></html>';
    exit();
}

$data = json_decode($response, true);
if (!isset($data['data']) || !is_array($data['data'])) {
    echo '<tr><td class="error">Réponse JSON invalide ou vide.</td></tr>';
    echo '</tbody></table></div></body></html>';
    exit();
}

$found = false;
foreach ($data['data'] as $item) {
    if (isset($item['content']) && $item['content'] === 'vztmpl') {
        $template_name = basename($item['volid']);
        echo '<tr><td>' . htmlspecialchars($template_name) . '</td></tr>';
        $found = true;
    }
}

if (!$found) {
    echo '<tr><td class="error">Aucun modèle LXC trouvé.</td></tr>';
}

echo '
            </tbody>
        </table>
    </div>
</body>
</html>';
?>
