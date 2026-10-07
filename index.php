<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Backend AGES - API Documentation</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
        }
        
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 40px;
            text-align: center;
        }
        
        .header h1 {
            font-size: 3em;
            margin-bottom: 10px;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.2);
        }
        
        .header p {
            font-size: 1.2em;
            opacity: 0.9;
        }
        
        .content {
            padding: 40px;
        }
        
        .section {
            margin-bottom: 40px;
        }
        
        .section h2 {
            color: #667eea;
            font-size: 2em;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 3px solid #667eea;
        }
        
        .cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .card {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
            cursor: pointer;
            text-decoration: none;
            color: inherit;
            display: block;
        }
        
        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
        }
        
        .card h3 {
            color: #764ba2;
            font-size: 1.5em;
            margin-bottom: 10px;
        }
        
        .card p {
            color: #555;
            line-height: 1.6;
        }
        
        .stats {
            display: flex;
            justify-content: space-around;
            flex-wrap: wrap;
            gap: 20px;
            margin: 30px 0;
        }
        
        .stat {
            text-align: center;
            padding: 20px;
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            min-width: 150px;
        }
        
        .stat-number {
            font-size: 3em;
            font-weight: bold;
            color: #667eea;
        }
        
        .stat-label {
            color: #666;
            margin-top: 5px;
        }
        
        .btn {
            display: inline-block;
            padding: 15px 30px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            text-decoration: none;
            border-radius: 50px;
            font-weight: bold;
            transition: all 0.3s ease;
            margin: 5px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 7px 20px rgba(0,0,0,0.3);
        }
        
        .btn-secondary {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        }
        
        .actions {
            text-align: center;
            margin: 40px 0;
        }
        
        .footer {
            background: #f5f5f5;
            padding: 20px;
            text-align: center;
            color: #666;
        }
        
        .emoji {
            font-size: 1.5em;
        }
        
        .list {
            list-style: none;
            padding: 0;
        }
        
        .list li {
            padding: 12px 0;
            border-bottom: 1px solid #eee;
            display: flex;
            align-items: center;
        }
        
        .list li:before {
            content: "✓";
            color: #667eea;
            font-weight: bold;
            margin-right: 10px;
            font-size: 1.2em;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🎓 Backend AGES</h1>
            <p>API REST pour la Gestion d'Académie de Langues</p>
        </div>
        
        <div class="content">
            <!-- Stats -->
            <div class="stats">
                <div class="stat">
                    <div class="stat-number">18</div>
                    <div class="stat-label">Contrôleurs</div>
                </div>
                <div class="stat">
                    <div class="stat-number">130+</div>
                    <div class="stat-label">Endpoints</div>
                </div>
                <div class="stat">
                    <div class="stat-number">127+</div>
                    <div class="stat-label">Fonctions</div>
                </div>
                <div class="stat">
                    <div class="stat-number">100%</div>
                    <div class="stat-label">Complet</div>
                </div>
            </div>
            
            <!-- Actions principales -->
            <div class="actions">
                <a href="Route/test_api.html" class="btn"><span class="emoji">🧪</span> Tester l'API</a>
                <a href="Route/api.php/roles" class="btn btn-secondary"><span class="emoji">🚀</span> API Endpoint</a>
            </div>
            
            <!-- Ressources -->
            <div class="section">
                <h2><span class="emoji">📚</span> Ressources API</h2>
                <div class="cards">
                    <a href="#" class="card" onclick="alert('Voir README_API.md'); return false;">
                        <h3>📖 Documentation API</h3>
                        <p>Documentation complète de tous les endpoints avec exemples cURL</p>
                    </a>
                    
                    <a href="Route/test_api.html" class="card">
                        <h3>🧪 Interface de Test</h3>
                        <p>Testez tous les endpoints depuis votre navigateur</p>
                    </a>
                    
                    <a href="#" class="card" onclick="alert('Voir SUMMARY.md'); return false;">
                        <h3>📋 Récapitulatif</h3>
                        <p>Vue d'ensemble complète du projet et de ses fonctionnalités</p>
                    </a>
                    
                    <a href="#" class="card" onclick="alert('Voir database/seed_data.sql'); return false;">
                        <h3>🌱 Données de Test</h3>
                        <p>Script SQL pour insérer des données de test</p>
                    </a>
                </div>
            </div>
            
            <!-- Endpoints disponibles -->
            <div class="section">
                <h2><span class="emoji">🎯</span> Endpoints Disponibles</h2>
                <div class="cards">
                    <div class="card">
                        <h3>📅 Années Scolaires</h3>
                        <p>GET, POST, PUT, DELETE /annees-scolaires</p>
                    </div>
                    
                    <div class="card">
                        <h3>👥 Rôles</h3>
                        <p>GET, POST, PUT, DELETE /roles</p>
                    </div>
                    
                    <div class="card">
                        <h3>🔐 Utilisateurs</h3>
                        <p>GET, POST, PUT, DELETE /users</p>
                    </div>
                    
                    <div class="card">
                        <h3>🏢 Départements</h3>
                        <p>GET, POST, PUT, DELETE /departements</p>
                    </div>
                    
                    <div class="card">
                        <h3>📊 Niveaux</h3>
                        <p>GET, POST, PUT, DELETE /niveaux</p>
                    </div>
                    
                    <div class="card">
                        <h3>🎓 Types Formation</h3>
                        <p>GET, POST, PUT, DELETE /types-formations</p>
                    </div>
                    
                    <div class="card">
                        <h3>👨‍🏫 Formateurs</h3>
                        <p>GET, POST, PUT, DELETE /formateurs</p>
                    </div>
                    
                    <div class="card">
                        <h3>🚪 Salles</h3>
                        <p>GET, POST, PUT, DELETE /salles</p>
                    </div>
                    
                    <div class="card">
                        <h3>📚 Sessions</h3>
                        <p>GET, POST, PUT, DELETE /sessions</p>
                    </div>
                    
                    <div class="card">
                        <h3>👥 Groupes</h3>
                        <p>GET, POST, PUT, DELETE /groupes</p>
                    </div>
                    
                    <div class="card">
                        <h3>🎓 Auditeurs</h3>
                        <p>GET, POST, PUT, DELETE /auditeurs</p>
                    </div>
                    
                    <div class="card">
                        <h3>📝 Inscriptions</h3>
                        <p>GET, POST, PUT, DELETE /inscriptions</p>
                    </div>
                    
                    <div class="card">
                        <h3>📅 Emplois du Temps</h3>
                        <p>GET, POST, PUT, DELETE /emplois-du-temps</p>
                    </div>
                    
                    <div class="card">
                        <h3>📋 Évaluations</h3>
                        <p>GET, POST, PUT, DELETE /evaluations</p>
                    </div>
                    
                    <div class="card">
                        <h3>📊 Paramètres Éval.</h3>
                        <p>GET, POST, PUT, DELETE /parametres-evaluations</p>
                    </div>
                    
                    <div class="card">
                        <h3>💯 Notes</h3>
                        <p>GET, POST, PUT, DELETE /notes</p>
                    </div>
                    
                    <div class="card">
                        <h3>🎯 Passages Niveau</h3>
                        <p>GET, POST, PUT, DELETE /passages-niveaux</p>
                    </div>
                    
                    <div class="card">
                        <h3>✅ Présences</h3>
                        <p>GET, POST, PUT, DELETE /presences</p>
                    </div>
                </div>
            </div>
            
            <!-- Fonctionnalités -->
            <div class="section">
                <h2><span class="emoji">✨</span> Fonctionnalités Principales</h2>
                <ul class="list">
                    <li>Les mots de passe sont enregistrés en clair (pratique déconseillée)</li>
                    <li>Gestion complète des utilisateurs et rôles</li>
                    <li>Gestion académique (années, sessions, groupes)</li>
                    <li>Gestion des formateurs et ressources (salles)</li>
                    <li>Validation complète des données</li>
                    <li>Requêtes préparées (protection SQL injection)</li>
                    <li>Codes HTTP appropriés et gestion d'erreurs</li>
                    <li>Documentation exhaustive</li>
                    <li>Interface de test interactive</li>
                    <li>Architecture MVC propre et maintenable</li>
                </ul>
            </div>
            
            <!-- Démarrage rapide -->
            <div class="section">
                <h2><span class="emoji">🚀</span> Démarrage Rapide</h2>
                <div style="background: #f5f5f5; padding: 20px; border-radius: 10px; font-family: monospace;">
                    <p><strong>1. Importer la base de données :</strong></p>
                    <code style="color: #667eea;">mysql -u root -p < database/DB\ Gestion\ Academie.sql</code>
                    <br><br>
                    <p><strong>2. Insérer les données de test :</strong></p>
                    <code style="color: #667eea;">mysql -u root -p academie_langues3 < database/seed_data.sql</code>
                    <br><br>
                    <p><strong>3. Tester l'API :</strong></p>
                    <code style="color: #667eea;">curl http://localhost/Backend_AGES/Route/api.php/roles</code>
                </div>
            </div>
            
            <!-- Comptes de test -->
            <div class="section">
                <h2><span class="emoji">🔑</span> Comptes de Test</h2>
                <div style="background: #fff3cd; padding: 20px; border-radius: 10px; border-left: 5px solid #ffc107;">
                    <p><strong>Administrateur :</strong> admin@ages.sn / admin123</p>
                    <p><strong>Scolarité :</strong> scolarite@ages.sn / scolarite123</p>
                </div>
            </div>
        </div>
        
        <div class="footer">
            <p>Développé avec ❤️ pour Backend AGES | Version 3.0.0 | © 2024</p>
            <p>API REST complète avec 18 contrôleurs et 130+ endpoints ✨</p>
        </div>
    </div>
</body>
</html>
