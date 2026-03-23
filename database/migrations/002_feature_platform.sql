ALTER TABLE flags
    ADD COLUMN flag_kind VARCHAR(32) NOT NULL DEFAULT 'release' AFTER description,
    ADD COLUMN variants JSON NULL AFTER options,
    ADD COLUMN default_variant_key VARCHAR(100) NULL AFTER variants,
    ADD COLUMN expires_at DATETIME(6) NULL AFTER updated_at,
    ADD COLUMN last_evaluated_at DATETIME(6) NULL AFTER expires_at,
    ADD COLUMN stale_status VARCHAR(32) NOT NULL DEFAULT 'active' AFTER last_evaluated_at,
    ADD COLUMN prerequisites JSON NULL AFTER stale_status;

CREATE TABLE environments (
    id CHAR(36) PRIMARY KEY,
    project_id CHAR(36) NOT NULL,
    `key` VARCHAR(100) NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 100,
    status VARCHAR(32) NOT NULL DEFAULT 'active',
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    deleted_at DATETIME(6) NULL,
    CONSTRAINT fk_environments_project FOREIGN KEY (project_id) REFERENCES projects(id),
    CONSTRAINT uq_environments_project_key UNIQUE (project_id, `key`)
);

CREATE TABLE segments (
    id CHAR(36) PRIMARY KEY,
    project_id CHAR(36) NOT NULL,
    `key` VARCHAR(100) NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    rules JSON NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'active',
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    deleted_at DATETIME(6) NULL,
    CONSTRAINT fk_segments_project FOREIGN KEY (project_id) REFERENCES projects(id),
    CONSTRAINT uq_segments_project_key UNIQUE (project_id, `key`)
);

CREATE TABLE flag_environment_configs (
    id CHAR(36) PRIMARY KEY,
    flag_id CHAR(36) NOT NULL,
    environment_id CHAR(36) NOT NULL,
    default_value JSON NULL,
    default_variant_key VARCHAR(100) NULL,
    rules JSON NOT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    CONSTRAINT fk_flag_env_configs_flag FOREIGN KEY (flag_id) REFERENCES flags(id),
    CONSTRAINT fk_flag_env_configs_environment FOREIGN KEY (environment_id) REFERENCES environments(id),
    CONSTRAINT uq_flag_env_configs_flag_environment UNIQUE (flag_id, environment_id)
);

CREATE TABLE evaluation_events (
    id CHAR(36) PRIMARY KEY,
    project_id CHAR(36) NOT NULL,
    environment_id CHAR(36) NOT NULL,
    flag_id CHAR(36) NOT NULL,
    client_id CHAR(36) NOT NULL,
    variant_key VARCHAR(100) NULL,
    value JSON NOT NULL,
    reason VARCHAR(64) NOT NULL,
    matched_rule VARCHAR(255) NULL,
    context JSON NOT NULL,
    created_at DATETIME(6) NOT NULL,
    CONSTRAINT fk_evaluation_events_project FOREIGN KEY (project_id) REFERENCES projects(id),
    CONSTRAINT fk_evaluation_events_environment FOREIGN KEY (environment_id) REFERENCES environments(id),
    CONSTRAINT fk_evaluation_events_flag FOREIGN KEY (flag_id) REFERENCES flags(id),
    CONSTRAINT fk_evaluation_events_client FOREIGN KEY (client_id) REFERENCES clients(id)
);

CREATE INDEX idx_environments_project_id ON environments(project_id);
CREATE INDEX idx_segments_project_id ON segments(project_id);
CREATE INDEX idx_flag_env_configs_flag_id ON flag_environment_configs(flag_id);
CREATE INDEX idx_flag_env_configs_environment_id ON flag_environment_configs(environment_id);
CREATE INDEX idx_evaluation_events_project_environment ON evaluation_events(project_id, environment_id, created_at);
CREATE INDEX idx_evaluation_events_flag ON evaluation_events(flag_id, created_at);
CREATE INDEX idx_evaluation_events_client ON evaluation_events(client_id, created_at);

INSERT INTO environments (id, project_id, `key`, name, description, is_default, sort_order, status, created_at, updated_at, deleted_at)
SELECT UUID(), p.id, 'development', 'Development', 'Local and developer testing environment', 0, 10, 'active', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), NULL
FROM projects p
WHERE p.status != 'deleted';

INSERT INTO environments (id, project_id, `key`, name, description, is_default, sort_order, status, created_at, updated_at, deleted_at)
SELECT UUID(), p.id, 'staging', 'Staging', 'Pre-production verification environment', 0, 20, 'active', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), NULL
FROM projects p
WHERE p.status != 'deleted';

INSERT INTO environments (id, project_id, `key`, name, description, is_default, sort_order, status, created_at, updated_at, deleted_at)
SELECT UUID(), p.id, 'production', 'Production', 'Default live environment', 1, 30, 'active', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), NULL
FROM projects p
WHERE p.status != 'deleted';

INSERT INTO flag_environment_configs (id, flag_id, environment_id, default_value, default_variant_key, rules, created_at, updated_at)
SELECT UUID(), f.id, e.id, f.default_value, f.default_variant_key, JSON_ARRAY(), UTC_TIMESTAMP(6), UTC_TIMESTAMP(6)
FROM flags f
JOIN environments e
  ON e.project_id = f.project_id
 AND e.`key` = 'production';
