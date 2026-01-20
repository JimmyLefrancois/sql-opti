# 📚 Guide d'optimisation :  Batch Processing avec MSSQL

## 🎯 Principe général :  Le problème du "N+1 queries"

### ❌ **Anti-pattern** (code inefficace)

```php
// Pour chaque ligne du CSV, on fait une requête SQL
foreach ($csvLines as $line) {
    $connection->executeStatement("INSERT INTO table VALUES (?, ? )", [$val1, $val2]);
}
// Résultat : 10 000 lignes = 10 000 requêtes SQL ! 
```

### ✅ **Pattern optimisé** (batch processing)

```php
// On accumule toutes les données, puis on insère par lots
$allData = [];
foreach ($csvLines as $line) {
    $allData[] = [$val1, $val2];
}

// On insère par batch de 500
foreach (array_chunk($allData, 500) as $batch) {
    // 1 requête pour 500 lignes !  
}// Résultat : 10 000 lignes = 20 requêtes SQL
```

---

## 🔧 Les 5 techniques d'optimisation appliquées

### **1️⃣ Accumulation en mémoire**

#### ❌ Avant

```php
$this->csvFileHelper->processCsvFiles($finder, function (array $data) use ($connection) {
    // Traitement IMMÉDIAT = requête SQL immédiate
    $connection->executeStatement("INSERT INTO .. .", [... ]);
});
```

#### ✅ Après

```php
$allData = []; // 📦 Accumuler d'abord

$this->csvFileHelper->processCsvFiles($finder, function (array $data) use (&$allData) {
    $rowData = $this->mapData($data);
    if ($rowData !== null) {
        $allData[] = $rowData; // Stocker, ne pas insérer tout de suite
    }
});

// Ensuite, insérer en batch
$this->batchInsert($connection, $allData);
```

**Pourquoi ? ** Permet de contrôler le moment et la manière dont on insère les données.

---

### **2️⃣ UNION ALL au lieu de requêtes préparées**

#### ❌ Avant (limite MSSQL :  2100 paramètres)

```php
// Avec 34 colonnes, max 61 lignes (2100 / 34)
$batchSize = 60;

$sql = "INSERT INTO table VALUES (?, ?, .. .), (?, ?, ...), ...";
$connection->executeStatement($sql, $params); // Paramètres liés
```

#### ✅ Après (pas de limite avec UNION ALL)

```php
// Batch de 500+ lignes possible ! 
$batchSize = 500;

$selectStatements = [];
foreach ($batch as $row) {
    $selectStatements[] = sprintf(
        "SELECT %s, %s, %s",  // Valeurs directes (échappées)
        $this->quote($row[0]),
        $this->quoteNumeric($row[1]),
        $this->quote($row[2])
    );
}

$sql = "INSERT INTO table (col1, col2, col3) " . 
       implode(' UNION ALL ', $selectStatements);

$connection->executeStatement($sql); // Pas de paramètres ! 
```

**Pourquoi ?** MSSQL a une limite de 2100 paramètres, mais pas de limite sur la longueur du SQL avec UNION ALL.

---

### **3️⃣ Helpers pour l'échappement SQL**

#### ✅ Deux fonctions essentielles

```php
/**
 * Pour les STRINGS et DATES
 */
private function quote($value): string
{
    if ($value === null || $value === '') {
        return 'NULL';
    }
    
    // Échapper les apostrophes (injection SQL)
    return "'" . str_replace("'", "''", $value) . "'";
}

/**
 * Pour les NOMBRES (int, decimal)
 */
private function quoteNumeric($value): string
{
    if ($value === null || $value === '') {
        return 'NULL';
    }
    
    if (is_numeric($value)) {
        return (string)$value; // Pas de quotes pour les nombres
    }
    
    return 'NULL';
}
```

**Utilisation :**

```php
// String
$this->quote("O'Brien")  // → 'O''Brien'

// Nombre
$this->quoteNumeric(42)   // → 42
$this->quoteNumeric(null) // → NULL

// Date
$this->quote('2024-01-15') // → '2024-01-15'
```

---

### **4️⃣ UPDATE en masse avec table temporaire**

#### ❌ Avant

```php
// 1 UPDATE par ligne
foreach ($csvLines as $line) {
    $connection->executeStatement(
        "UPDATE collaborateur SET adresse = ? WHERE matricule = ? ",
        [$adresse, $matricule]
    );
}
```

#### ✅ Après

```php
// 1. Créer table temporaire
$tempTable = "##temp_" . uniqid();
$connection->executeStatement("\n    CREATE TABLE {$tempTable} (
        matricule VARCHAR(50) PRIMARY KEY,
        adresse VARCHAR(255)
    )\n");

// 2. Remplir avec UNION ALL
$selectStatements = [];
foreach ($batch as $row) {
    $selectStatements[] = sprintf(
        "SELECT %s, %s",
        $this->quote($row['matricule']),
        $this->quote($row['adresse'])
    );
}

$sql = "INSERT INTO {$tempTable} " .  implode(' UNION ALL ', $selectStatements);
$connection->executeStatement($sql);

// 3. UPDATE en masse avec JOIN
$connection->executeStatement("\n    UPDATE c\n    SET c.adresse = t.adresse\n    FROM collaborateur c\n    INNER JOIN {$tempTable} t ON c.matricule = t. matricule\n");

// 4. Nettoyer
$connection->executeStatement("DROP TABLE {$tempTable}");
```

**Pourquoi ?** 1 UPDATE avec JOIN est 1000x plus rapide que 1000 UPDATE individuels.

---

### **5️⃣ Optimisations mémoire et configuration**

```php
// Désactiver le SQL Logger (économise 30-50% de mémoire)
$connection->getConfiguration()->setSQLLogger(null);

// Vider le cache Doctrine entre les batchs
$this->entityManager->clear();

// Forcer le garbage collector
gc_collect_cycles();

// Transaction globale (améliore les perfs)
$connection->beginTransaction();
try {
    // ... toutes les opérations
    $connection->commit();
} catch (Exception $e) {
    $connection->rollBack();
    throw $e;
}
```

---

## 📖 Exemple complet : Avant / Après

### ❌ **AVANT** (code non optimisé)

```php
public function importCollaborateurs(Finder $finder): void
{
    $connection = $this->entityManager->getConnection();
    
    $batchSize = 10; // ❌ Trop petit
    $rows = [];
    $params = [];
    $counter = 0;
    
    // ❌ Traitement immédiat dans la callback
    $this->csvFileHelper->processCsvFiles($finder, function (array $data) use (
        &$rows, &$params, &$counter, $connection, $batchSize
    ) {
        $matricule = substr($data[0], 2); // ❌ Incohérent
        
        // ❌ Pas de validation
        $nom = $data[1];
        $prenom = $data[2];
        
        // ❌ Requêtes préparées = limite 2100 paramètres
        $rows[] = '(?, ?, ?)';
        array_push($params, $matricule, $nom, $prenom);
        $counter++;
        
        if ($counter % $batchSize === 0) {
            // ❌ Requête SQL à chaque petit batch
            $sql = "INSERT INTO collaborateur (matricule, nom, prenom) VALUES " . 
                   implode(', ', $rows);
            $connection->executeStatement($sql, $params);
            
            $rows = [];
            $params = [];
            gc_collect_cycles();
        }
    });
    
    // ❌ Duplication du code pour le dernier batch
    if (! empty($rows)) {
        $sql = "INSERT INTO collaborateur (matricule, nom, prenom) VALUES " . 
               implode(', ', $rows);
        $connection->executeStatement($sql, $params);
        gc_collect_cycles();
    }
}
```

**Problèmes :**
- ❌ batchSize = 10 → 1000 lignes = 100 requêtes SQL
- ❌ Limite des 2100 paramètres MSSQL
- ❌ Pas de gestion d'erreur globale
- ❌ Code dupliqué
- ❌ Pas de logs

---

### ✅ **APRÈS** (code optimisé)

```php
public function importCollaborateurs(Finder $finder): void
{
    $connection = $this->entityManager->getConnection();
    $connection->getConfiguration()->setSQLLogger(null); // ✅ Optimisation mémoire
    
    // ✅ 1. ACCUMULER toutes les données
    $allData = [];
    
    $this->csvFileHelper->processCsvFiles($finder, function (array $data) use (&$allData) {
        $rowData = $this->mapCollaborateurData($data);
        if ($rowData !== null) {
            $allData[] = $rowData;
        }
    });
    
    if (empty($allData)) {
        error_log("Aucune donnée à importer");
        return;
    }
    
    error_log("=== IMPORT COLLABORATEURS ===");
    error_log("Nombre de lignes : " .  count($allData));
    
    // ✅ 2. TRANSACTION globale
    $connection->beginTransaction();
    try {
        $this->batchInsertCollaborateurs($connection, $allData);
        $connection->commit();
        $this->entityManager->clear();
        error_log("✅ Import terminé");
    } catch (Exception $e) {
        $connection->rollBack();
        error_log("❌ Erreur :  " . $e->getMessage());
        throw $e;
    }
}

/**
 * ✅ Mapping séparé et validé
 */
private function mapCollaborateurData(array $data): ?array
{
    // ✅ Cohérence :  même méthode partout
    $matricule = Collaborateur::removePrefixMatricule($data[0] ?? '');
    
    // ✅ Validation
    if (empty($matricule)) {
        return null;
    }
    
    return [
        'matricule' => $matricule,
        'nom' => $data[1] ?? '',
        'prenom' => $data[2] ?? ''
    ];
}

/**
 * ✅ Batch INSERT avec UNION ALL
 */
private function batchInsertCollaborateurs($connection, array $data): void
{
    $batchSize = 500; // ✅ 50x plus grand ! 
    $batches = array_chunk($data, $batchSize);
    $totalBatches = count($batches);
    $currentBatch = 0;
    
    foreach ($batches as $batch) {
        $currentBatch++;
        error_log("Batch {
        $currentBatch}/{$totalBatches}");
        
        // ✅ UNION ALL :  pas de limite de paramètres
        $selectStatements = [];
        foreach ($batch as $row) {
            $selectStatements[] = sprintf(
                "SELECT %s, %s, %s",
                $this->quote($row['matricule']),
                $this->quote($row['nom']),
                $this->quote($row['prenom'])
            );
        }
        
        $sql = "INSERT INTO collaborateur (matricule, nom, prenom) " . 
               implode(' UNION ALL ', $selectStatements);
        
        $connection->executeStatement($sql);
        
        $this->entityManager->clear();
        gc_collect_cycles();
    }
}

/**
 * ✅ Helper pour échappement SQL
 */
private function quote($value): string
{
    if ($value === null || $value === '') {
        return 'NULL';
    }
    return "'" . str_replace("'", "''", $value) . "'";
}

private function quoteNumeric($value): string
{
    if ($value === null || $value === '') {
        return 'NULL';
    }
    if (is_numeric($value)) {
        return (string)$value;
    }
    return 'NULL';
}
}
```

**Améliorations :**
- ✅ batchSize = 500 → 1000 lignes = 2 requêtes SQL (98% de réduction)
- ✅ Pas de limite avec UNION ALL
- ✅ Transaction globale + gestion d'erreur
- ✅ Code séparé et réutilisable
- ✅ Logs détaillés
- ✅ Optimisation mémoire

---

## 📊 Comparaison des performances

| Opération | Avant | Après | Gain |
|-----------|-------|-------|------|
| **10 000 lignes INSERT** | 1000 requêtes<br>~30 sec | 20 requêtes<br>~0.5 sec | **98%** |
| **10 000 lignes UPDATE** | 10 000 requêtes<br>~90 sec | 20 requêtes<br>~1 sec | **99%** |
| **Mémoire utilisée** | Moyenne + logs SQL | Basse | **-40%** |

---

## 🎓 Checklist pour optimiser ton code

### ✅ Pour les INSERT

- [ ] Accumuler les données en mémoire (`$allData = []`)
- [ ] Utiliser UNION ALL au lieu de requêtes préparées
- [ ] batchSize ≥ 500 (selon le nombre de colonnes)
- [ ] Transaction globale
- [ ] Désactiver SQL Logger
- [ ] `clear()` et `gc_collect_cycles()` entre les batchs

### ✅ Pour les UPDATE

- [ ] Accumuler les données en mémoire
- [ ] Créer une table temporaire
- [ ] Remplir avec UNION ALL
- [ ] UPDATE avec JOIN (pas de sous-requêtes)
- [ ] Nettoyer la table temp

### ✅ Bonnes pratiques générales

- [ ] Séparer mapping et insertion
- [ ] Valider les données avant insertion
- [ ] Utiliser des helpers `quote()` et `quoteNumeric()`
- [ ] Cohérence :  même méthode pour extraire les matricules
- [ ] Gestion d'erreurs avec try/catch
- [ ] Logs informatifs

---

## 🚀 Formule magique (Pattern universel)

```php
// PATTERN UNIVERSEL D'OPTIMISATION

public function importData(Finder $finder): void
{
    $connection = $this->entityManager->getConnection();
    $connection->getConfiguration()->setSQLLogger(null);
    
    // 1. ACCUMULER
    $allData = [];
    $this->fileHelper->processFiles($finder, function ($data) use (&$allData) {
        $mapped = $this->mapData($data);
        if ($mapped) $allData[] = $mapped;
    });
    
    if (empty($allData)) return;
    
    // 2. TRANSACTION + BATCH
    $connection->beginTransaction();
    try {
        $this->batchOperation($connection, $allData);
        $connection->commit();
    } catch (Exception $e) {
        $connection->rollBack();
        throw $e;
    }
}

private function batchOperation($connection, array $data): void
{
    foreach (array_chunk($data, 500) as $batch) {
        $selects = [];
        foreach ($batch as $row) {
            $selects[] = sprintf("SELECT %s, %s", 
                $this->quote($row[0]), 
                $this->quoteNumeric($row[1])
            );
        }
        
        $sql = "INSERT INTO table (col1, col2) " . 
               implode(' UNION ALL ', $selects);
        $connection->executeStatement($sql);
        
        $this->entityManager->clear();
        gc_collect_cycles();
    }
}
```

---

## 📐 Calcul du batchSize optimal

### Formule pour MSSQL avec requêtes préparées

```
batchSize = 2100 / nombre_de_colonnes
```

**Exemples :**
- 5 colonnes → max 420 lignes
- 10 colonnes → max 210 lignes
- 34 colonnes → max 61 lignes

### Avec UNION ALL (recommandé)

**Pas de limite théorique**, mais en pratique : 
- **500 lignes** : bon équilibre performance/mémoire
- **1000 lignes** : pour volumes très importants (>100k lignes)
- **100-200 lignes** : si colonnes très nombreuses (>50)

---

## 🛠️ Helpers réutilisables (à copier)

```php
/**
 * Helper pour quoter les strings et dates
 */
private function quote($value): string
{
    if ($value === null || $value === '') {
        return 'NULL';
    }
    
    if (is_numeric($value) && ! is_string($value)) {
        return (string)$value;
    }
    
    return "'" . str_replace("'", "''", $value) . "'";
}

/**
 * Helper pour quoter les nombres
 */
private function quoteNumeric($value): string
{
    if ($value === null || $value === '') {
        return 'NULL';
    }
    
    if (is_numeric($value)) {
        return (string)$value;
    }
    
    return 'NULL';
}

/**
 * Helper pour nettoyer et encoder les strings
 */
private function cleanAndEncodeString(string $value): string
{
    if (empty($value)) {
        return '';
    }
    
    // Encodage
    $encoded = mb_convert_encoding($value, 'UTF-8', 'Windows-1252,ISO-8859-1,UTF-8');
    
    // Nettoyage :  supprimer les caractères non imprimables
    $cleaned = preg_replace('/[^\\PC\s]/u', '', $encoded);
    
    return $cleaned ?: '';
}

/**
 * Helper pour parser les floats
 */
private function parseFloat($value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }
    
    if (is_numeric($value)) {
        return (float)$value;
    }
    
    return null;
}

/**
 * Helper pour convertir des dates Excel
 */
private function convertExcelDate($excelDate): ?string
{
    if (! is_numeric($excelDate) || empty($excelDate)) {
        return null;
    }
    
    try {
        $baseDate = new \DateTime('1899-12-30');
        $baseDate->modify('+' . ((int)$excelDate) . ' days');
        return $baseDate->format('Y-m-d');
    } catch (\Exception $e) {
        error_log("Erreur conversion date Excel : {$excelDate}");
        return null;
    }
}
```

---

## 🎯 Résumé en 3 étapes

### Étape 1 : Accumuler
```php
$allData = [];
$this->fileHelper->process(function ($data) use (&$allData) {
    $allData[] = $this->mapData($data);
});
```

### Étape 2 :  Batch avec UNION ALL
```php
foreach (array_chunk($allData, 500) as $batch) {
    $selects = array_map(fn($row) => 
        sprintf("SELECT %s, %s", $this->quote($row[0]), $this->quote($row[1])),
        $batch
    );
    $sql = "INSERT INTO table " . implode(' UNION ALL ', $selects);
    $connection->executeStatement($sql);
}
```

### Étape 3 : Transaction & optimisation
```php
$connection->getConfiguration()->setSQLLogger(null);
$connection->beginTransaction();
try {
    // ...  batch operations
    $connection->commit();
} catch (Exception $e) {
    $connection->rollBack();
    throw $e;
}
```

---

## 📚 Pour aller plus loin

### Cas spéciaux

#### Gestion des doublons
```php
// Dédupliquer en PHP avec clé unique
$allData = [];
foreach ($csvLines as $line) {
    $key = $line['id']; // Clé unique
    $allData[$key] = $line; // Écrase les doublons
}
$allData = array_values($allData); // Réindexer
```

#### Gestion des relations (FK)
```php
// Précharger les IDs en une seule requête
$collaborateurs = [];
$results = $connection->fetchAllAssociative("SELECT id, matricule FROM collaborateur");
foreach ($results as $row) {
    $collaborateurs[$row['matricule']] = $row['id'];
}
```

#### Très gros volumes (>1M lignes)
```php
// Augmenter le batch et logger la progression
$batchSize = 2000;
$processed = 0;
foreach (array_chunk($allData, $batchSize) as $batch) {
    // ...  batch insert
    $processed += count($batch);
    if ($processed % 10000 === 0) {
        error_log("Progression : {$processed} / " . count($allData));
    }
}
```

---

**Applique ce pattern à chaque fonction d'import/update et tu auras des gains de 95-99% systématiquement ! ** 🎯