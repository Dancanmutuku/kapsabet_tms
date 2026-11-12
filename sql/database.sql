CREATE DATABASE IF NOT EXISTS kapsabet_tms CHARACTER SET utf8mb4;
USE kapsabet_tms;
SET FOREIGN_KEY_CHECKS=0;
DROP TABLE IF EXISTS audit_log, incident, feedback, payment, booking, passenger, trip, queue_entry, crew_member, vehicle_document, vehicle, fare, route, stage, app_user, role;
SET FOREIGN_KEY_CHECKS=1;

CREATE TABLE role (id SMALLINT AUTO_INCREMENT PRIMARY KEY, role_name VARCHAR(30) NOT NULL UNIQUE) ENGINE=InnoDB;
CREATE TABLE app_user (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, role_id SMALLINT NOT NULL, full_name VARCHAR(100) NOT NULL,
 phone_no VARCHAR(13) UNIQUE, username VARCHAR(40) UNIQUE, password_hash VARCHAR(255) NOT NULL,
 status VARCHAR(10) NOT NULL DEFAULT 'ACTIVE' CHECK (status IN ('ACTIVE','INACTIVE')),
 failed_logins SMALLINT NOT NULL DEFAULT 0, locked_until DATETIME NULL, last_login_at DATETIME NULL,
 created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (role_id) REFERENCES role(id) ON DELETE RESTRICT) ENGINE=InnoDB;
CREATE TABLE stage (id SMALLINT AUTO_INCREMENT PRIMARY KEY, stage_name VARCHAR(60) NOT NULL UNIQUE, town VARCHAR(40)) ENGINE=InnoDB;
CREATE TABLE route (
 id SMALLINT AUTO_INCREMENT PRIMARY KEY, origin_stage_id SMALLINT NOT NULL, dest_stage_id SMALLINT NOT NULL,
 distance_km DECIMAL(5,1) NOT NULL CHECK (distance_km > 0),
 UNIQUE (origin_stage_id, dest_stage_id), CHECK (origin_stage_id <> dest_stage_id),
 FOREIGN KEY (origin_stage_id) REFERENCES stage(id), FOREIGN KEY (dest_stage_id) REFERENCES stage(id)) ENGINE=InnoDB;
CREATE TABLE fare (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, route_id SMALLINT NOT NULL, amount DECIMAL(8,2) NOT NULL CHECK (amount > 0),
 effective_from DATE NOT NULL, effective_to DATE NULL, approved_by BIGINT NULL,
 UNIQUE (route_id, effective_from),
 FOREIGN KEY (route_id) REFERENCES route(id), FOREIGN KEY (approved_by) REFERENCES app_user(id)) ENGINE=InnoDB;
CREATE TABLE vehicle (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, owner_id BIGINT NOT NULL, reg_no VARCHAR(10) NOT NULL UNIQUE,
 make_model VARCHAR(60), seat_capacity SMALLINT NOT NULL DEFAULT 14 CHECK (seat_capacity BETWEEN 1 AND 14),
 status VARCHAR(10) NOT NULL DEFAULT 'ACTIVE' CHECK (status IN ('ACTIVE','SUSPENDED','RETIRED')),
 created_at DATETIME DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (owner_id) REFERENCES app_user(id)) ENGINE=InnoDB;
CREATE TABLE vehicle_document (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, vehicle_id BIGINT NOT NULL,
 doc_type VARCHAR(12) NOT NULL CHECK (doc_type IN ('INSURANCE','INSPECTION')),
 expiry_date DATE NOT NULL, uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (vehicle_id) REFERENCES vehicle(id)) ENGINE=InnoDB;
CREATE TABLE crew_member (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, user_id BIGINT NOT NULL UNIQUE,
 crew_type VARCHAR(10) NOT NULL CHECK (crew_type IN ('DRIVER','CONDUCTOR')),
 licence_no VARCHAR(30) UNIQUE, FOREIGN KEY (user_id) REFERENCES app_user(id)) ENGINE=InnoDB;
CREATE TABLE queue_entry (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, vehicle_id BIGINT NOT NULL, stage_id SMALLINT NOT NULL, registered_by BIGINT NOT NULL,
 queue_date DATE NOT NULL, queued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, queue_position INT NOT NULL,
 stage_fee DECIMAL(8,2) NULL,
 status VARCHAR(10) NOT NULL DEFAULT 'WAITING' CHECK (status IN ('WAITING','DISPATCHED','CANCELLED')),
 waiting_vehicle BIGINT AS (IF(status='WAITING', vehicle_id, NULL)) STORED,
 UNIQUE (stage_id, queue_date, queue_position), UNIQUE (waiting_vehicle),
 FOREIGN KEY (vehicle_id) REFERENCES vehicle(id), FOREIGN KEY (stage_id) REFERENCES stage(id),
 FOREIGN KEY (registered_by) REFERENCES app_user(id)) ENGINE=InnoDB;
CREATE TABLE trip (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, queue_id BIGINT NULL UNIQUE, vehicle_id BIGINT NOT NULL, route_id SMALLINT NOT NULL,
 driver_id BIGINT NOT NULL, conductor_id BIGINT NOT NULL, scheduled_departure DATETIME NOT NULL,
 departure_time DATETIME NULL, arrival_time DATETIME NULL, expected_revenue DECIMAL(10,2) NULL, counted_cash DECIMAL(10,2) NULL,
 recon_status VARCHAR(10) NOT NULL DEFAULT 'PENDING' CHECK (recon_status IN ('PENDING','RECONCILED','MISMATCH')),
 status VARCHAR(10) NOT NULL DEFAULT 'LOADING' CHECK (status IN ('SCHEDULED','LOADING','DEPARTED','CLOSED')),
 created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (queue_id) REFERENCES queue_entry(id), FOREIGN KEY (vehicle_id) REFERENCES vehicle(id),
 FOREIGN KEY (route_id) REFERENCES route(id), FOREIGN KEY (driver_id) REFERENCES crew_member(id),
 FOREIGN KEY (conductor_id) REFERENCES crew_member(id)) ENGINE=InnoDB;
CREATE TABLE passenger (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, full_name VARCHAR(100), phone_no VARCHAR(13) NOT NULL UNIQUE,
 consent_given BOOLEAN NOT NULL CHECK (consent_given = TRUE), consent_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB;
CREATE TABLE booking (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, trip_id BIGINT NOT NULL, passenger_id BIGINT NULL,
 seat_no SMALLINT NOT NULL CHECK (seat_no BETWEEN 1 AND 14),
 channel VARCHAR(10) NOT NULL DEFAULT 'CONDUCTOR' CHECK (channel IN ('ONLINE','CONDUCTOR')),
 fare_amount DECIMAL(8,2) NOT NULL,
 status VARCHAR(10) NOT NULL DEFAULT 'BOARDED' CHECK (status IN ('HELD','CONFIRMED','BOARDED','CANCELLED','EXPIRED')),
 created_by BIGINT NULL, client_uuid CHAR(32) NOT NULL UNIQUE, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
 active_seat SMALLINT AS (IF(status IN ('HELD','CONFIRMED','BOARDED'), seat_no, NULL)) STORED,
 UNIQUE (trip_id, active_seat),
 FOREIGN KEY (trip_id) REFERENCES trip(id), FOREIGN KEY (passenger_id) REFERENCES passenger(id),
 FOREIGN KEY (created_by) REFERENCES app_user(id)) ENGINE=InnoDB;
CREATE TABLE payment (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, trip_id BIGINT NOT NULL, booking_id BIGINT NOT NULL,
 amount DECIMAL(8,2) NOT NULL CHECK (amount > 0), method VARCHAR(6) NOT NULL CHECK (method IN ('MPESA','CASH')),
 status VARCHAR(10) NOT NULL DEFAULT 'SUCCESS' CHECK (status IN ('PENDING','SUCCESS','FAILED','REVERSED')),
 mpesa_receipt_no VARCHAR(20) NULL UNIQUE, receipt_no VARCHAR(20) NOT NULL UNIQUE, recorded_by BIGINT NULL,
 paid_at DATETIME DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (trip_id) REFERENCES trip(id), FOREIGN KEY (booking_id) REFERENCES booking(id)) ENGINE=InnoDB;
CREATE TABLE feedback (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, trip_id BIGINT NULL, vehicle_id BIGINT NULL, passenger_id BIGINT NULL,
 category VARCHAR(12) NOT NULL CHECK (category IN ('COMPLIMENT','COMPLAINT','SUGGESTION')),
 rating SMALLINT NULL CHECK (rating BETWEEN 1 AND 5), message VARCHAR(1000) NOT NULL,
 status VARCHAR(12) NOT NULL DEFAULT 'NEW' CHECK (status IN ('NEW','IN_PROGRESS','RESOLVED')),
 response VARCHAR(1000) NULL, responded_by BIGINT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
 CHECK (trip_id IS NOT NULL OR vehicle_id IS NOT NULL),
 FOREIGN KEY (trip_id) REFERENCES trip(id), FOREIGN KEY (vehicle_id) REFERENCES vehicle(id),
 FOREIGN KEY (passenger_id) REFERENCES passenger(id)) ENGINE=InnoDB;
CREATE TABLE incident (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, vehicle_id BIGINT NOT NULL, trip_id BIGINT NULL, reported_by BIGINT NULL,
 incident_type VARCHAR(15) NOT NULL, description VARCHAR(1000), reported_at DATETIME DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (vehicle_id) REFERENCES vehicle(id), FOREIGN KEY (trip_id) REFERENCES trip(id)) ENGINE=InnoDB;
CREATE TABLE audit_log (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, user_id BIGINT NULL, action VARCHAR(10) NOT NULL, table_name VARCHAR(40),
 record_id BIGINT, old_values JSON NULL, new_values JSON NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB;

INSERT INTO role (role_name) VALUES ('ADMIN'),('OFFICIAL'),('CLERK'),('CONDUCTOR'),('DRIVER'),('OWNER');
INSERT INTO stage (stage_name, town) VALUES ('Kapsabet stage','Kapsabet'),('Eldoret stage','Eldoret');
INSERT INTO route (origin_stage_id, dest_stage_id, distance_km) VALUES (1,2,50.0),(2,1,50.0);
INSERT INTO fare (route_id, amount, effective_from) VALUES (1,250,'2026-01-01'),(2,250,'2026-01-01');
