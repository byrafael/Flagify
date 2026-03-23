CREATE TABLE projects (
    id CHAR(36) PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(100) NOT NULL UNIQUE,
    description TEXT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'active',
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    deleted_at DATETIME(6) NULL
);

CREATE TABLE flags (
    id CHAR(36) PRIMARY KEY,
    project_id CHAR(36) NOT NULL,
    `key` VARCHAR(100) NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    `type` VARCHAR(32) NOT NULL,
    default_value JSON NOT NULL,
    options JSON NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'active',
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    CONSTRAINT fk_flags_project FOREIGN KEY (project_id) REFERENCES projects(id),
    CONSTRAINT uq_flags_project_key UNIQUE (project_id, `key`)
);

CREATE TABLE clients (
    id CHAR(36) PRIMARY KEY,
    project_id CHAR(36) NOT NULL,
    `key` VARCHAR(100) NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'active',
    metadata JSON NOT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    deleted_at DATETIME(6) NULL,
    CONSTRAINT fk_clients_project FOREIGN KEY (project_id) REFERENCES projects(id),
    CONSTRAINT uq_clients_project_key UNIQUE (project_id, `key`)
);

CREATE TABLE flag_overrides (
    id CHAR(36) PRIMARY KEY,
    project_id CHAR(36) NOT NULL,
    flag_id CHAR(36) NOT NULL,
    client_id CHAR(36) NOT NULL,
    value JSON NOT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    CONSTRAINT fk_overrides_project FOREIGN KEY (project_id) REFERENCES projects(id),
    CONSTRAINT fk_overrides_flag FOREIGN KEY (flag_id) REFERENCES flags(id),
    CONSTRAINT fk_overrides_client FOREIGN KEY (client_id) REFERENCES clients(id),
    CONSTRAINT uq_overrides_flag_client UNIQUE (flag_id, client_id)
);

CREATE TABLE api_keys (
    id CHAR(36) PRIMARY KEY,
    project_id CHAR(36) NULL,
    client_id CHAR(36) NULL,
    name VARCHAR(255) NOT NULL,
    prefix VARCHAR(64) NOT NULL UNIQUE,
    secret_hash VARCHAR(255) NOT NULL,
    kind VARCHAR(32) NOT NULL,
    scopes JSON NOT NULL,
    last_used_at DATETIME(6) NULL,
    expires_at DATETIME(6) NULL,
    revoked_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL,
    CONSTRAINT fk_api_keys_project FOREIGN KEY (project_id) REFERENCES projects(id),
    CONSTRAINT fk_api_keys_client FOREIGN KEY (client_id) REFERENCES clients(id)
);

CREATE INDEX idx_api_keys_project_id ON api_keys(project_id);
CREATE INDEX idx_api_keys_client_id ON api_keys(client_id);
