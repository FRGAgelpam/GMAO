<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Portail Gestion Industrielle</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #2c3e50;
            --gmao-color: #3498db;
            --gpao-color: #27ae60;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: #f4f7f6;
            margin: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
        }

        .container {
            text-align: center;
            width: 100%;
            max-width: 900px;
            padding: 20px;
        }

        h1 { color: var(--primary); margin-bottom: 40px; font-weight: 600; }

        .portal-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 30px;
        }

        .card {
            background: white;
            padding: 40px;
            border-radius: 20px;
            text-decoration: none;
            color: var(--primary);
            box-shadow: 0 10px 25px rgba(0,0,0,0.05);
            transition: all 0.3s ease;
            border: 2px solid transparent;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .card:hover {
            transform: translateY(-10px);
            box-shadow: 0 15px 35px rgba(0,0,0,0.1);
        }

        .card.gmao:hover { border-color: var(--gmao-color); }
        .card.gpao:hover { border-color: var(--gpao-color); }

        .icon {
            font-size: 4rem;
            margin-bottom: 20px;
        }

        .gmao .icon { color: var(--gmao-color); }
        .gpao .icon { color: var(--gpao-color); }

        h2 { margin: 10px 0; font-size: 1.5rem; }
        p { color: #7f8c8d; font-size: 0.9rem; }

        .badge {
            margin-top: 15px;
            padding: 5px 15px;
            border-radius: 50px;
            color: white;
            font-size: 0.8rem;
            text-transform: uppercase;
        }
        .bg-gmao { background: var(--gmao-color); }
        .bg-gpao { background: var(--gpao-color); }
    </style>
<?php include 'pwa_head.php'; ?>
</head>
<body>

<div class="container">
    <h1>Bienvenue sur votre portail</h1>
    
    <div class="portal-grid">
        <a href="gmao/index.php" class="card gmao">
            <div class="icon"><i class="fa-solid fa-gears"></i></div>
            <h2>GMAO</h2>
            <p>Gestion de la Maintenance Assistée par Ordinateur</p>
            <span class="badge bg-gmao">Accéder</span>
        </a>

        <a href="gpao/index.php" class="card gpao">
            <div class="icon"><i class="fa-solid fa-industry"></i></div>
            <h2>GPAO</h2>
            <p>Gestion de la Production Assistée par Ordinateur</p>
            <span class="badge bg-gpao">Accéder</span>
        </a>
    </div>
</div>

</body>
</html>