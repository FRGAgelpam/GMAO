<?php
// Cette fiche faisait doublon avec aide_service.php (section #demande), qui couvre le même
// sujet de façon plus complète (suivi, idées, annualisation portail inclus) et reste
// accessible même sans connexion. On redirige plutôt que de maintenir deux versions.
header("Location: aide_service.php#demande");
exit();
