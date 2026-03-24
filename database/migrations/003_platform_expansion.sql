CREATE TABLE identities (
    id CHAR(36) PRIMARY KEY,
    project_id CHAR(36) NOT NULL,
    kind VARCHAR(32) NOT NULL,
    identifier VARCHAR(191) NOT NULL,
    display_name VARCHAR(255) NULL,
    description TEXT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'active',
    client_id CHAR(36) NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    deleted_at DATETIME(6) NULL,
    CONSTRAINT fk_identities_project FOREIGN KEY (project_id) REFERENCES projects(id),
    CONSTRAINT fk_identities_client FOREIGN KEY (client_id) REFERENCES clients(id),
    CONSTRAINT uq_identities_project_kind_identifier UNIQUE (project_id, kind, identifier),
    CONSTRAINT uq_identities_client UNIQUE (client_id)
);

CREATE INDEX idx_identities_project_status ON identities(project_id, status);
CREATE INDEX idx_identities_project_kind_identifier ON identities(project_id, kind, identifier);

CREATE TABLE identity_traits (
    id CHAR(36) PRIMARY KEY,
    identity_id CHAR(36) NOT NULL,
    trait_key VARCHAR(191) NOT NULL,
    trait_value JSON NOT NULL,
    value_type VARCHAR(32) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    CONSTRAINT fk_identity_traits_identity FOREIGN KEY (identity_id) REFERENCES identities(id),
    CONSTRAINT uq_identity_traits_identity_key UNIQUE (identity_id, trait_key)
);

CREATE INDEX idx_identity_traits_identity ON identity_traits(identity_id);
CREATE INDEX idx_identity_traits_key ON identity_traits(trait_key);

CREATE TABLE audit_logs (
    id CHAR(36) PRIMARY KEY,
    project_id CHAR(36) NOT NULL,
    environment_id CHAR(36) NULL,
    resource_type VARCHAR(64) NOT NULL,
    resource_id CHAR(36) NOT NULL,
    resource_key VARCHAR(191) NULL,
    action VARCHAR(64) NOT NULL,
    actor_type VARCHAR(32) NOT NULL,
    actor_id CHAR(36) NULL,
    actor_name VARCHAR(255) NULL,
    request_id CHAR(36) NULL,
    before_payload JSON NULL,
    after_payload JSON NULL,
    metadata JSON NOT NULL,
    created_at DATETIME(6) NOT NULL,
    CONSTRAINT fk_audit_logs_project FOREIGN KEY (project_id) REFERENCES projects(id),
    CONSTRAINT fk_audit_logs_environment FOREIGN KEY (environment_id) REFERENCES environments(id)
);

CREATE INDEX idx_audit_logs_project_created ON audit_logs(project_id, created_at DESC);
CREATE INDEX idx_audit_logs_project_resource_created ON audit_logs(project_id, resource_type, created_at DESC);
CREATE INDEX idx_audit_logs_project_environment_created ON audit_logs(project_id, environment_id, created_at DESC);

CREATE TABLE change_requests (
    id CHAR(36) PRIMARY KEY,
    project_id CHAR(36) NOT NULL,
    environment_id CHAR(36) NOT NULL,
    resource_type VARCHAR(64) NOT NULL,
    resource_id CHAR(36) NOT NULL,
    status VARCHAR(32) NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,
    proposed_by_principal_id CHAR(36) NULL,
    reviewed_by_principal_id CHAR(36) NULL,
    applied_by_principal_id CHAR(36) NULL,
    proposed_payload JSON NOT NULL,
    approved_payload JSON NULL,
    base_snapshot_checksum CHAR(64) NULL,
    applied_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    CONSTRAINT fk_change_requests_project FOREIGN KEY (project_id) REFERENCES projects(id),
    CONSTRAINT fk_change_requests_environment FOREIGN KEY (environment_id) REFERENCES environments(id)
);

CREATE INDEX idx_change_requests_project_created ON change_requests(project_id, created_at DESC);
CREATE INDEX idx_change_requests_project_environment ON change_requests(project_id, environment_id, created_at DESC);

CREATE TABLE code_references (
    id CHAR(36) PRIMARY KEY,
    project_id CHAR(36) NOT NULL,
    flag_id CHAR(36) NOT NULL,
    source_type VARCHAR(32) NOT NULL,
    source_name VARCHAR(255) NULL,
    reference_path VARCHAR(1024) NOT NULL,
    reference_line INT NULL,
    reference_context TEXT NULL,
    observed_at DATETIME(6) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    CONSTRAINT fk_code_references_project FOREIGN KEY (project_id) REFERENCES projects(id),
    CONSTRAINT fk_code_references_flag FOREIGN KEY (flag_id) REFERENCES flags(id)
);

CREATE INDEX idx_code_references_project_flag ON code_references(project_id, flag_id);
CREATE INDEX idx_code_references_project_observed ON code_references(project_id, observed_at DESC);

ALTER TABLE environments
    ADD COLUMN requires_change_requests TINYINT(1) NOT NULL DEFAULT 0 AFTER is_default;

ALTER TABLE evaluation_events
    MODIFY COLUMN client_id CHAR(36) NULL,
    ADD COLUMN identity_id CHAR(36) NULL AFTER client_id,
    ADD COLUMN identity_kind VARCHAR(32) NULL AFTER identity_id,
    ADD COLUMN identity_identifier VARCHAR(191) NULL AFTER identity_kind,
    ADD COLUMN traits JSON NULL AFTER context,
    ADD COLUMN transient_traits JSON NULL AFTER traits,
    ADD CONSTRAINT fk_evaluation_events_identity FOREIGN KEY (identity_id) REFERENCES identities(id);

CREATE INDEX idx_evaluation_events_project_created ON evaluation_events(project_id, created_at);
CREATE INDEX idx_evaluation_events_project_flag_created ON evaluation_events(project_id, flag_id, created_at);
CREATE INDEX idx_evaluation_events_project_variant_created ON evaluation_events(project_id, variant_key, created_at);

INSERT INTO identities (id, project_id, kind, identifier, display_name, description, status, client_id, created_at, updated_at, deleted_at)
SELECT UUID(), c.project_id, 'client', c.`key`, c.name, c.description, c.status, c.id, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), c.deleted_at
FROM clients c
WHERE c.deleted_at IS NULL
  AND NOT EXISTS (
      SELECT 1
      FROM identities i
      WHERE i.client_id = c.id
  );
