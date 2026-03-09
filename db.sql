-- ============================================
-- SAAS LENOIR-MEC - Schema de base de données
-- ============================================

DROP TABLE IF EXISTS audit_logs CASCADE;
DROP TABLE IF EXISTS login_attempts CASCADE;
DROP TABLE IF EXISTS machines CASCADE;
DROP TABLE IF EXISTS interventions CASCADE;
DROP TABLE IF EXISTS clients CASCADE;
DROP TABLE IF EXISTS users CASCADE;
DROP TABLE IF EXISTS active_sessions CASCADE;

-- ============================================
-- TABLE DES UTILISATEURS (TECHNICIENS)
-- ============================================
CREATE TABLE users (
    id SERIAL PRIMARY KEY,
    nom VARCHAR(100) NOT NULL UNIQUE,
    prenom VARCHAR(100) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'technicien' CHECK (role IN ('technicien', 'admin')),
    actif BOOLEAN NOT NULL DEFAULT TRUE,
    must_change_password BOOLEAN NOT NULL DEFAULT TRUE,
    avatar_base64 TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ============================================
-- TABLE DES CLIENTS
-- ============================================
CREATE TABLE clients (
    id SERIAL PRIMARY KEY,
    nom_societe VARCHAR(255) NOT NULL,
    adresse TEXT,
    code_postal VARCHAR(20),
    ville VARCHAR(100),
    pays VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ============================================
-- TABLE DES INTERVENTIONS
-- ============================================
CREATE TABLE interventions (
    id SERIAL PRIMARY KEY,
    numero_arc VARCHAR(50) NOT NULL UNIQUE,
    client_id INT NOT NULL REFERENCES clients(id),
    technicien_id INT NOT NULL REFERENCES users(id),
    contact_nom VARCHAR(100),
    contact_fonction VARCHAR(100),
    contact_email VARCHAR(255),
    contact_telephone VARCHAR(50),
    date_intervention DATE NOT NULL DEFAULT CURRENT_DATE,
    statut VARCHAR(50) NOT NULL DEFAULT 'Brouillon' CHECK (statut IN ('Brouillon', 'Terminee', 'Envoyee')),
    
    -- "Le client souhaite" options
    souhait_rapport_unique BOOLEAN DEFAULT FALSE,
    souhait_offre_pieces BOOLEAN DEFAULT FALSE,
    souhait_pieces_intervention BOOLEAN DEFAULT FALSE,
    souhait_aucune_offre BOOLEAN DEFAULT FALSE,
    
    -- Signatures (stockées en base64 PNG)
    signature_client TEXT,
    signature_technicien TEXT,
    nom_signataire_client VARCHAR(100),
    date_signature TIMESTAMP,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ============================================
-- TABLE DES MACHINES (PAR APPAREIL CONTRÔLÉ)
-- ============================================
CREATE TABLE machines (
    id SERIAL PRIMARY KEY,
    intervention_id INT NOT NULL REFERENCES interventions(id) ON DELETE CASCADE,
    numero_of VARCHAR(50),
    designation VARCHAR(100) NOT NULL, -- OV, APRF, ED-X, PAP/TAP...
    annee_fabrication VARCHAR(4),
    donnees_controle JSONB, -- Stocke tous les points de contrôle "Correct/Pas correct" etc.
    mesures JSONB, -- Stocke les valeurs Gauss, isolement, etc.
    photos JSONB, -- Tableaux de data URIs ou chemins S3
    commentaires TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ============================================
-- TABLE D'AUDIT ET SECURITE
-- ============================================
CREATE TABLE audit_logs (
    id SERIAL PRIMARY KEY,
    user_id INT REFERENCES users(id),
    action VARCHAR(50) NOT NULL,
    details TEXT,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE login_attempts (
    ip_address VARCHAR(45) PRIMARY KEY,
    attempts INT DEFAULT 1,
    last_attempt TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ============================================
-- INDEX
-- ============================================
CREATE INDEX idx_interventions_date ON interventions(date_intervention);
CREATE INDEX idx_interventions_arc ON interventions(numero_arc);
CREATE INDEX idx_machines_interv ON machines(intervention_id);

-- ============================================
-- DONNÉES INITIALES (TECHNICIENS)
-- password = password123
-- ============================================
INSERT INTO users (nom, prenom, password_hash, role, must_change_password) VALUES
('CHRIST',      'Olivier',   '$2y$10$KJ7isoaShujh8NKqoYOE/OUHe.Z63dzC068Lu3jJYYxPXK8ngiQjG', 'admin', TRUE),
('LOTITO',      'Pierre',    '$2y$10$KJ7isoaShujh8NKqoYOE/OUHe.Z63dzC068Lu3jJYYxPXK8ngiQjG', 'technicien', TRUE),
('BUDIN',       'Aymeric',   '$2y$10$KJ7isoaShujh8NKqoYOE/OUHe.Z63dzC068Lu3jJYYxPXK8ngiQjG', 'technicien', TRUE),
('MANGIN',      'Maxime',    '$2y$10$KJ7isoaShujh8NKqoYOE/OUHe.Z63dzC068Lu3jJYYxPXK8ngiQjG', 'technicien', TRUE),
('LAFOND',      'Vivian',    '$2y$10$KJ7isoaShujh8NKqoYOE/OUHe.Z63dzC068Lu3jJYYxPXK8ngiQjG', 'technicien', TRUE),
('CHRISTIANY',  'Jean-Paul', '$2y$10$KJ7isoaShujh8NKqoYOE/OUHe.Z63dzC068Lu3jJYYxPXK8ngiQjG', 'technicien', TRUE),
('TONETTO',     'Jean-Marc', '$2y$10$KJ7isoaShujh8NKqoYOE/OUHe.Z63dzC068Lu3jJYYxPXK8ngiQjG', 'technicien', TRUE),
('TG',          'Tom',       '$2y$10$KJ7isoaShujh8NKqoYOE/OUHe.Z63dzC068Lu3jJYYxPXK8ngiQjG', 'admin', TRUE)
ON CONFLICT (nom) DO UPDATE
    SET password_hash        = EXCLUDED.password_hash,
        must_change_password = EXCLUDED.must_change_password;
