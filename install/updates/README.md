# Scripts de mise à jour de la base de données

Ce dossier reste vide tant qu'aucune mise à jour ne nécessite de modifier la structure de la base de données.

Quand une nouvelle version ajoute une colonne, une table ou modifie une donnée existante, un fichier SQL est ajouté ici, nommé d'après le numéro de version qui l'introduit, par exemple :

```
install/updates/1.1.0.sql
```

## Comment l'utiliser

1. Regarde le fichier `VERSION` à la racine du dépôt : c'est la version que tu es en train d'installer/récupérer.
2. Compare-le au fichier `VERSION` de ton installation actuelle.
3. Exécute, **dans l'ordre des numéros de version**, tous les fichiers de ce dossier dont le numéro est supérieur à ta version actuelle. Exemple : si tu passes de la 1.0.0 à la 1.2.0 et qu'il existe `1.1.0.sql` et `1.2.0.sql`, exécute les deux, dans cet ordre :

```bash
mysql -u gmao_user -p gmao_db < install/updates/1.1.0.sql
mysql -u gmao_user -p gmao_db < install/updates/1.2.0.sql
```

**Ne jamais réimporter `install/schema.sql` sur une base existante** — ce fichier sert uniquement à une toute première installation et effacerait les données déjà présentes.
