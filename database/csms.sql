CREATE TABLE students (
    student_id INT AUTO_INCREMENT PRIMARY KEY,
    reg_number VARCHAR(50) UNIQUE,
    name VARCHAR(100),
    email VARCHAR(100) UNIQUE,
    password VARCHAR(255),
    semester VARCHAR(20),
    status ENUM('active','inactive','pending') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    locked_until DATETIME NULL DEFAULT NULL,
    failed_login_attempts INT NOT NULL DEFAULT 0,
    password_history TEXT NULL DEFAULT NULL,
    force_password_change TINYINT(1) NOT NULL DEFAULT 0,
    password_changed_at DATETIME NULL DEFAULT NULL
);

CREATE TABLE courses (
    course_id INT AUTO_INCREMENT PRIMARY KEY,
    course_name VARCHAR(100),
    semester VARCHAR(20)
);

CREATE TABLE course_registrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT,
    course_id INT,
    FOREIGN KEY (student_id) REFERENCES students(student_id),
    FOREIGN KEY (course_id) REFERENCES courses(course_id)
);

CREATE TABLE attendance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT,
    course_id INT,
    total_classes INT,
    attended_classes INT,
    FOREIGN KEY (student_id) REFERENCES students(student_id),
    FOREIGN KEY (course_id) REFERENCES courses(course_id)
);

CREATE TABLE results (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT,
    course_id INT,
    marks INT,
    grade VARCHAR(2),
    FOREIGN KEY (student_id) REFERENCES students(student_id),
    FOREIGN KEY (course_id) REFERENCES courses(course_id)
);

-- Security columns for admins table
CREATE TABLE admins (
    admin_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100),
    email VARCHAR(100) UNIQUE,
    password VARCHAR(255),
    deleted TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    locked_until DATETIME NULL DEFAULT NULL,
    failed_login_attempts INT NOT NULL DEFAULT 0,
    password_history TEXT NULL DEFAULT NULL,
    force_password_change TINYINT(1) NOT NULL DEFAULT 0,
    password_changed_at DATETIME NULL DEFAULT NULL
);

-- Security columns for teachers table
CREATE TABLE teachers (
    teacher_id INT AUTO_INCREMENT PRIMARY KEY,
    fullname VARCHAR(100),
    email VARCHAR(100) UNIQUE,
    password VARCHAR(255),
    status VARCHAR(20) DEFAULT 'active',
    deleted TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    locked_until DATETIME NULL DEFAULT NULL,
    failed_login_attempts INT NOT NULL DEFAULT 0,
    password_history TEXT NULL DEFAULT NULL,
    force_password_change TINYINT(1) NOT NULL DEFAULT 0,
    password_changed_at DATETIME NULL DEFAULT NULL
);

-- Centralised password reset tokens (shared by all user types)
CREATE TABLE password_reset_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_type VARCHAR(20) NOT NULL COMMENT 'admins | teachers | students',
    user_id INT NOT NULL,
    email VARCHAR(100) NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    verification_code VARCHAR(6) NOT NULL,
    code_attempts INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    is_used TINYINT(1) NOT NULL DEFAULT 0,
    INDEX idx_token (token),
    INDEX idx_email_type (email, user_type)
);

-- Alter statements for existing deployments (run if tables already exist)
-- ALTER TABLE students    ADD COLUMN locked_until DATETIME NULL DEFAULT NULL, ADD COLUMN failed_login_attempts INT NOT NULL DEFAULT 0, ADD COLUMN password_history TEXT NULL DEFAULT NULL, ADD COLUMN force_password_change TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN password_changed_at DATETIME NULL DEFAULT NULL;
-- ALTER TABLE admins      ADD COLUMN locked_until DATETIME NULL DEFAULT NULL, ADD COLUMN failed_login_attempts INT NOT NULL DEFAULT 0, ADD COLUMN password_history TEXT NULL DEFAULT NULL, ADD COLUMN force_password_change TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN password_changed_at DATETIME NULL DEFAULT NULL;
-- ALTER TABLE teachers    ADD COLUMN locked_until DATETIME NULL DEFAULT NULL, ADD COLUMN failed_login_attempts INT NOT NULL DEFAULT 0, ADD COLUMN password_history TEXT NULL DEFAULT NULL, ADD COLUMN force_password_change TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN password_changed_at DATETIME NULL DEFAULT NULL;
