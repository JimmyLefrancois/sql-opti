<?php
/**
 * 🚀 Benchmark des optimisations SQL
 * 
 * Script de test pour comparer les performances avant/après optimisation
 * Basé sur le guide GUIDE_OPTIMISATION_BATCH_PROCESSING. md
 * 
 * Configuration requise :
 * - PHP 7.4+
 * - Extension PDO
 * - MySQL/MariaDB ou SQLite
 * 
 * Usage :  php benchmark.php
 */

// ============================================================================
// CONFIGURATION
// ============================================================================

const DB_TYPE = 'mysql'; // 'mysql' ou 'sqlite'
const DB_HOST = 'localhost';
const DB_PORT = 3306;
const DB_NAME = 'test_optimisation';
const DB_USER = 'root';
const DB_PASS = '';
const DB_CHARSET = 'utf8mb4';

// Nombre de lignes à générer pour les tests
const NB_COLLABORATEURS = 10000;
const NB_UPDATES = 5000;

// Taille des batchs
const BATCH_SIZE_PETIT = 10;    // ❌ Non optimisé
const BATCH_SIZE_OPTIMISE = 500; // ✅ Optimisé

// ============================================================================
// CLASSE DE BENCHMARK
// ============================================================================

class SQLBenchmark
{
    private PDO $pdo;
    private array $stats = [];
    
    public function __construct()
    {
        $this->connectDatabase();
        $this->setupTables();
    }
    
    /**
     * Connexion à la base de données
     */
    private function connectDatabase(): void
    {
        try {
            if (DB_TYPE === 'sqlite') {
                $this->pdo = new PDO('sqlite:: memory: ');
                echo "📦 Connexion à SQLite (in-memory)\n";
            } else {
                $dsn = sprintf(
                    'mysql: host=%s;port=%d;dbname=%s;charset=%s',
                    DB_HOST,
                    DB_PORT,
                    DB_NAME,
                    DB_CHARSET
                );
                $this->pdo = new PDO($dsn, DB_USER, DB_PASS, [
                    PDO:: ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
                echo "📦 Connexion à MySQL :  " . DB_HOST . ":" . DB_PORT .  "/" . DB_NAME . "\n";
            }
        } catch (PDOException $e) {
            die("❌ Erreur de connexion :  " . $e->getMessage() . "\n");
        }
    }
    
    /**
     * Créer les tables de test
     */
    private function setupTables(): void
    {
        echo "\n🔧 Création des tables...\n";
        
        // DROP des tables existantes
        $this->pdo->exec("DROP TABLE IF EXISTS collaborateur");
        $this->pdo->exec("DROP TABLE IF EXISTS metier");
        $this->pdo->exec("DROP TABLE IF EXISTS contrat");
        
        // Table collaborateur
        $this->pdo->exec("
            CREATE TABLE collaborateur (
                id INT AUTO_INCREMENT PRIMARY KEY,
                matricule VARCHAR(50) UNIQUE NOT NULL,
                nom VARCHAR(100) NOT NULL,
                prenom VARCHAR(100) NOT NULL,
                email VARCHAR(255),
                date_naissance DATE,
                salaire DECIMAL(10,2),
                metier_id INT,
                contrat_id INT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
        
        // Table métier
        $this->pdo->exec("
            CREATE TABLE metier (
                id INT AUTO_INCREMENT PRIMARY KEY,
                intitule VARCHAR(255) NOT NULL,
                departement VARCHAR(100),
                niveau INT
            )
        ");
        
        // Table contrat
        $this->pdo->exec("
            CREATE TABLE contrat (
                id INT AUTO_INCREMENT PRIMARY KEY,
                type_contrat VARCHAR(50) NOT NULL,
                date_debut DATE NOT NULL,
                date_fin DATE,
                salaire_base DECIMAL(10,2)
            )
        ");
        
        echo "✅ Tables créées\n";
    }
    
    /**
     * Générer des données aléatoires
     */
    public function generateRandomData(int $count): array
    {
        echo "\n📊 Génération de {$count} collaborateurs aléatoires...\n";
        
        $noms = ['Martin', 'Bernard', 'Dubois', 'Thomas', 'Robert', 'Richard', 'Petit', 'Durand', 'Leroy', 'Moreau'];
        $prenoms = ['Jean', 'Marie', 'Pierre', 'Sophie', 'Luc', 'Julie', 'Paul', 'Anne', 'Michel', 'Claire'];
        $departements = ['IT', 'RH', 'Finance', 'Marketing', 'Production', 'Commercial'];
        $intitules = ['Développeur', 'Manager', 'Analyste', 'Chef de projet', 'Technicien', 'Consultant'];
        $typeContrats = ['CDI', 'CDD', 'ALTERNANT', 'STAGE'];
        
        $data = [];
        
        for ($i = 1; $i <= $count; $i++) {
            $nom = $noms[array_rand($noms)];
            $prenom = $prenoms[array_rand($prenoms)];
            
            $data[] = [
                'collaborateur' => [
                    'matricule' => str_pad($i, 6, '0', STR_PAD_LEFT),
                    'nom' => $nom,
                    'prenom' => $prenom,
                    'email' => strtolower($prenom .  '.' . $nom . $i . '@company.com'),
                    'date_naissance' => date('Y-m-d', strtotime('-' . rand(25, 60) . ' years')),
                    'salaire' => rand(25000, 80000),
                ],
                'metier' => [
                    'intitule' => $intitules[array_rand($intitules)],
                    'departement' => $departements[array_rand($departements)],
                    'niveau' => rand(1, 5),
                ],
                'contrat' => [
                    'type_contrat' => $typeContrats[array_rand($typeContrats)],
                    'date_debut' => date('Y-m-d', strtotime('-' .  rand(1, 3650) . ' days')),
                    'date_fin' => rand(0, 1) ? date('Y-m-d', strtotime('+' . rand(1, 730) . ' days')) : null,
                    'salaire_base' => rand(25000, 80000),
                ],
            ];
            
            if ($i % 1000 === 0) {
                echo "  Généré :  {$i} / {$count}\n";
            }
        }
        
        echo "✅ {$count} collaborateurs générés\n";
        
        return $data;
    }
    
    /**
     * ❌ INSERT NON OPTIMISÉ (1 requête par ligne)
     */
    public function testInsertNonOptimise(array $data): void
    {
        echo "\n" . str_repeat("=", 80) . "\n";
        echo "❌ TEST 1 : INSERT NON OPTIMISÉ (1 requête par ligne)\n";
        echo str_repeat("=", 80) . "\n";
        
        $this->cleanTables();
        
        $startTime = microtime(true);
        $startMemory = memory_get_usage();
        $queryCount = 0;
        
        $this->pdo->beginTransaction();
        
        foreach ($data as $item) {
            $c = $item['collaborateur'];
            $m = $item['metier'];
            $ct = $item['contrat'];
            
            // INSERT métier
            $stmt = $this->pdo->prepare("INSERT INTO metier (intitule, departement, niveau) VALUES (?, ?, ?)");
            $stmt->execute([$m['intitule'], $m['departement'], $m['niveau']]);
            $metierId = $this->pdo->lastInsertId();
            $queryCount++;
            
            // INSERT contrat
            $stmt = $this->pdo->prepare("INSERT INTO contrat (type_contrat, date_debut, date_fin, salaire_base) VALUES (?, ?, ?, ?)");
            $stmt->execute([$ct['type_contrat'], $ct['date_debut'], $ct['date_fin'], $ct['salaire_base']]);
            $contratId = $this->pdo->lastInsertId();
            $queryCount++;
            
            // INSERT collaborateur
            $stmt = $this->pdo->prepare("INSERT INTO collaborateur (matricule, nom, prenom, email, date_naissance, salaire, metier_id, contrat_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$c['matricule'], $c['nom'], $c['prenom'], $c['email'], $c['date_naissance'], $c['salaire'], $metierId, $contratId]);
            $queryCount++;
        }
        
        $this->pdo->commit();
        
        $endTime = microtime(true);
        $endMemory = memory_get_usage();
        
        $this->stats['insert_non_optimise'] = [
            'time' => $endTime - $startTime,
            'memory' => $endMemory - $startMemory,
            'queries' => $queryCount,
            'rows' => count($data),
        ];
        
        $this->printStats('insert_non_optimise');
    }
    
    /**
     * ✅ INSERT OPTIMISÉ (batch avec requêtes multiples)
     */
    public function testInsertOptimise(array $data): void
    {
        echo "\n" . str_repeat("=", 80) . "\n";
        echo "✅ TEST 2 :  INSERT OPTIMISÉ (batch de " . BATCH_SIZE_OPTIMISE . " lignes)\n";
        echo str_repeat("=", 80) . "\n";
        
        $this->cleanTables();
        
        $startTime = microtime(true);
        $startMemory = memory_get_usage();
        $queryCount = 0;
        
        $this->pdo->beginTransaction();
        
        $batches = array_chunk($data, BATCH_SIZE_OPTIMISE);
        $totalBatches = count($batches);
        $currentBatch = 0;
        
        foreach ($batches as $batch) {
            $currentBatch++;
            
            // ✅ INSERT métiers en masse
            $metierValues = [];
            $metierParams = [];
            foreach ($batch as $item) {
                $m = $item['metier'];
                $metierValues[] = "(?, ?, ?)";
                $metierParams[] = $m['intitule'];
                $metierParams[] = $m['departement'];
                $metierParams[] = $m['niveau'];
            }
            
            $sql = "INSERT INTO metier (intitule, departement, niveau) VALUES " . implode(', ', $metierValues);
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($metierParams);
            $firstMetierId = $this->pdo->lastInsertId();
            $queryCount++;
            
            // ✅ INSERT contrats en masse
            $contratValues = [];
            $contratParams = [];
            foreach ($batch as $item) {
                $ct = $item['contrat'];
                $contratValues[] = "(?, ?, ?, ?)";
                $contratParams[] = $ct['type_contrat'];
                $contratParams[] = $ct['date_debut'];
                $contratParams[] = $ct['date_fin'];
                $contratParams[] = $ct['salaire_base'];
            }
            
            $sql = "INSERT INTO contrat (type_contrat, date_debut, date_fin, salaire_base) VALUES " . implode(', ', $contratValues);
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($contratParams);
            $firstContratId = $this->pdo->lastInsertId();
            $queryCount++;
            
            // ✅ INSERT collaborateurs en masse avec les FK
            $collabValues = [];
            $collabParams = [];
            foreach ($batch as $index => $item) {
                $c = $item['collaborateur'];
                $collabValues[] = "(?, ?, ?, ?, ?, ?, ?, ?)";
                $collabParams[] = $c['matricule'];
                $collabParams[] = $c['nom'];
                $collabParams[] = $c['prenom'];
                $collabParams[] = $c['email'];
                $collabParams[] = $c['date_naissance'];
                $collabParams[] = $c['salaire'];
                $collabParams[] = $firstMetierId + $index;
                $collabParams[] = $firstContratId + $index;
            }
            
            $sql = "INSERT INTO collaborateur (matricule, nom, prenom, email, date_naissance, salaire, metier_id, contrat_id) VALUES " . implode(', ', $collabValues);
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($collabParams);
            $queryCount++;
            
            if ($currentBatch % 5 === 0) {
                echo "  Batch {$currentBatch}/{$totalBatches}\n";
            }
        }
        
        $this->pdo->commit();
        
        $endTime = microtime(true);
        $endMemory = memory_get_usage();
        
        $this->stats['insert_optimise'] = [
            'time' => $endTime - $startTime,
            'memory' => $endMemory - $startMemory,
            'queries' => $queryCount,
            'rows' => count($data),
        ];
        
        $this->printStats('insert_optimise');
    }
    
    /**
     * ❌ UPDATE NON OPTIMISÉ (1 requête par ligne)
     */
    public function testUpdateNonOptimise(int $count): void
    {
        echo "\n" . str_repeat("=", 80) . "\n";
        echo "❌ TEST 3 : UPDATE NON OPTIMISÉ (1 requête par ligne)\n";
        echo str_repeat("=", 80) . "\n";
        
        $startTime = microtime(true);
        $startMemory = memory_get_usage();
        $queryCount = 0;
        
        $this->pdo->beginTransaction();
        
        for ($i = 1; $i <= $count; $i++) {
            $matricule = str_pad($i, 6, '0', STR_PAD_LEFT);
            $nouveauSalaire = rand(30000, 90000);
            $nouvelEmail = 'updated_' . $i . '@company.com';
            
            $stmt = $this->pdo->prepare("UPDATE collaborateur SET salaire = ?, email = ? WHERE matricule = ?");
            $stmt->execute([$nouveauSalaire, $nouvelEmail, $matricule]);
            $queryCount++;
        }
        
        $this->pdo->commit();
        
        $endTime = microtime(true);
        $endMemory = memory_get_usage();
        
        $this->stats['update_non_optimise'] = [
            'time' => $endTime - $startTime,
            'memory' => $endMemory - $startMemory,
            'queries' => $queryCount,
            'rows' => $count,
        ];
        
        $this->printStats('update_non_optimise');
    }
    
    /**
     * ✅ UPDATE OPTIMISÉ (batch avec table temporaire)
     */
    public function testUpdateOptimise(int $count): void
    {
        echo "\n" . str_repeat("=", 80) . "\n";
        echo "✅ TEST 4 : UPDATE OPTIMISÉ (batch avec JOIN)\n";
        echo str_repeat("=", 80) . "\n";
        
        // Restaurer les valeurs d'origine
        $this->pdo->exec("UPDATE collaborateur SET email = CONCAT(LOWER(prenom), '.', LOWER(nom), id, '@company.com')");
        
        $startTime = microtime(true);
        $startMemory = memory_get_usage();
        $queryCount = 0;
        
        $this->pdo->beginTransaction();
        
        // Générer les données à mettre à jour
        $updates = [];
        for ($i = 1; $i <= $count; $i++) {
            $updates[] = [
                'matricule' => str_pad($i, 6, '0', STR_PAD_LEFT),
                'salaire' => rand(30000, 90000),
                'email' => 'updated_' .  $i . '@company.com',
            ];
        }
        
        $batches = array_chunk($updates, BATCH_SIZE_OPTIMISE);
        $totalBatches = count($batches);
        $currentBatch = 0;
        
        foreach ($batches as $batch) {
            $currentBatch++;
            
            // Créer table temporaire
            $this->pdo->exec("CREATE TEMPORARY TABLE IF NOT EXISTS temp_updates (
                matricule VARCHAR(50) PRIMARY KEY,
                salaire DECIMAL(10,2),
                email VARCHAR(255)
            )");
            $queryCount++;
            
            // Remplir la table temporaire
            $values = [];
            $params = [];
            foreach ($batch as $update) {
                $values[] = "(?, ?, ?)";
                $params[] = $update['matricule'];
                $params[] = $update['salaire'];
                $params[] = $update['email'];
            }
            
            $sql = "INSERT INTO temp_updates (matricule, salaire, email) VALUES " . implode(', ', $values);
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $queryCount++;
            
            // ✅ UPDATE en masse avec JOIN
            $this->pdo->exec("
                UPDATE collaborateur c
                INNER JOIN temp_updates t ON c.matricule = t.matricule
                SET c.salaire = t.salaire, c.email = t.email
            ");
            $queryCount++;
            
            // Nettoyer
            $this->pdo->exec("DROP TEMPORARY TABLE temp_updates");
            $queryCount++;
            
            if ($currentBatch % 5 === 0) {
                echo "  Batch {$currentBatch}/{$totalBatches}\n";
            }
        }
        
        $this->pdo->commit();
        
        $endTime = microtime(true);
        $endMemory = memory_get_usage();
        
        $this->stats['update_optimise'] = [
            'time' => $endTime - $startTime,
            'memory' => $endMemory - $startMemory,
            'queries' => $queryCount,
            'rows' => $count,
        ];
        
        $this->printStats('update_optimise');
    }
    
    /**
     * Nettoyer les tables
     */
    private function cleanTables(): void
    {
        $this->pdo->exec("TRUNCATE TABLE collaborateur");
        $this->pdo->exec("TRUNCATE TABLE metier");
        $this->pdo->exec("TRUNCATE TABLE contrat");
    }
    
    /**
     * Afficher les statistiques
     */
    private function printStats(string $test): void
    {
        $stats = $this->stats[$test];
        
        echo "\n📊 Résultats :\n";
        echo sprintf("  ⏱️  Temps : %.4f secondes\n", $stats['time']);
        echo sprintf("  💾 Mémoire : %s\n", $this->formatBytes($stats['memory']));
        echo sprintf("  🔢 Requêtes SQL : %d\n", $stats['queries']);
        echo sprintf("  📝 Lignes traitées : %d\n", $stats['rows']);
        echo sprintf("  ⚡ Vitesse : %.0f lignes/sec\n", $stats['rows'] / $stats['time']);
    }
    
    /**
     * Afficher le récapitulatif comparatif
     */
    public function printSummary(): void
    {
        echo "\n" . str_repeat("=", 80) . "\n";
        echo "📊 RÉCAPITULATIF COMPARATIF\n";
        echo str_repeat("=", 80) . "\n\n";
        
        // Comparaison INSERT
        if (isset($this->stats['insert_non_optimise']) && isset($this->stats['insert_optimise'])) {
            $nonOpti = $this->stats['insert_non_optimise'];
            $opti = $this->stats['insert_optimise'];
            
            echo "🔹 INSERT :\n";
            echo sprintf("  ❌ Non optimisé : %.2fs | %d requêtes\n", $nonOpti['time'], $nonOpti['queries']);
            echo sprintf("  ✅ Optimisé     : %.2fs | %d requêtes\n", $opti['time'], $opti['queries']);
            echo sprintf("  🚀 Gain temps   : %.1fx plus rapide\n", $nonOpti['time'] / $opti['time']);
            echo sprintf("  🚀 Gain requêtes:  %.1f%% de réduction\n\n", (1 - $opti['queries'] / $nonOpti['queries']) * 100);
        }
        
        // Comparaison UPDATE
        if (isset($this->stats['update_non_optimise']) && isset($this->stats['update_optimise'])) {
            $nonOpti = $this->stats['update_non_optimise'];
            $opti = $this->stats['update_optimise'];
            
            echo "🔹 UPDATE :\n";
            echo sprintf("  ❌ Non optimisé : %.2fs | %d requêtes\n", $nonOpti['time'], $nonOpti['queries']);
            echo sprintf("  ✅ Optimisé     : %. 2fs | %d requêtes\n", $opti['time'], $opti['queries']);
            echo sprintf("  🚀 Gain temps   : %.1fx plus rapide\n", $nonOpti['time'] / $opti['time']);
            echo sprintf("  🚀 Gain requêtes: %.1f%% de réduction\n", (1 - $opti['queries'] / $nonOpti['queries']) * 100);
        }
        
        echo "\n" . str_repeat("=", 80) . "\n";
    }
    
    /**
     * Formater les octets
     */
    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        } elseif ($bytes < 1048576) {
            return round($bytes / 1024, 2) . ' KB';
        } else {
            return round($bytes / 1048576, 2) . ' MB';
        }
    }
}

// ============================================================================
// EXÉCUTION DES TESTS
// ============================================================================

echo "\n";
echo str_repeat("=", 80) . "\n";
echo "🚀 BENCHMARK DES OPTIMISATIONS SQL\n";
echo str_repeat("=", 80) . "\n";

try {
    $benchmark = new SQLBenchmark();
    
    // Générer les données
    $data = $benchmark->generateRandomData(NB_COLLABORATEURS);
    
    // Tests INSERT
    $benchmark->testInsertNonOptimise($data);
    $benchmark->testInsertOptimise($data);
    
    // Tests UPDATE
    $benchmark->testUpdateNonOptimise(NB_UPDATES);
    $benchmark->testUpdateOptimise(NB_UPDATES);
    
    // Récapitulatif
    $benchmark->printSummary();
    
    echo "\n✅ Tests terminés avec succès !\n\n";
    
} catch (Exception $e) {
    echo "\n❌ Erreur :  " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
