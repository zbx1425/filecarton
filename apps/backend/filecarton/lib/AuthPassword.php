<?php

namespace FileCarton;

abstract class PasswordAuth implements AuthProvider {
    public function kind(): string {
        return 'password';
    }

    public function validateConfig(): array {
        return [];
    }

    /**
     * Return an identity on success, null on failure.
     * Must not throw on bad credentials.
     *
     * @return AuthIdentity|null
     */
    abstract public function verify(string $username, string $password);
}

class StaticPasswordAuth extends PasswordAuth {
    const DUMMY = '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8Ke77en67J.tdmi';

    /** @var array id => hash */
    private $users;

    public function __construct(array $opts = []) {
        if (isset($opts['users']) && is_array($opts['users'])) {
            $this->users = $opts['users'];
        } elseif ($opts !== []) {
            $this->users = $opts;
        } else {
            $this->users = [];
            foreach (Auth::staticUserList() as $row) {
                if (!is_array($row) || !isset($row['id']) || !is_string($row['id'])) continue;
                if (isset($row['passwordHash']) && is_string($row['passwordHash']) && $row['passwordHash'] !== '') {
                    $this->users[$row['id']] = $row['passwordHash'];
                }
            }
        }
    }

    public function id(): string { return 'password'; }

    public function label(): string { return 'Username and password'; }

    public function validateConfig(): array {
        $p = [];
        foreach (Auth::staticUserList() as $row) {
            if (!is_array($row) || !isset($row['id']) || !is_string($row['id'])) continue;
            if (isset($row['passwordHash']) && is_string($row['passwordHash']) && $row['passwordHash'] !== '') {
                if (!preg_match('/^(\$2[ayb]\$|\$argon2)/', $row['passwordHash'])) {
                    $p[] = 'FILECARTON_STATIC_USER_LIST passwordHash must be password_hash().';
                    break;
                }
            }
        }
        return $p;
    }

    public function verify(string $username, string $password) {
        if (!isset($this->users[$username]) || !is_string($this->users[$username])) {
            // Prevent timing-based attack
            password_verify($password, self::DUMMY);
            return null;
        }
        if (!password_verify($password, $this->users[$username])) {
            return null;
        }
        $display = $username;
        foreach (Auth::staticUserList() as $row) {
            if (is_array($row) && isset($row['id']) && $row['id'] === $username) {
                if (isset($row['displayName']) && is_string($row['displayName']) && $row['displayName'] !== '') {
                    $display = $row['displayName'];
                }
                break;
            }
        }
        return new AuthIdentity($username, $display, $this->id());
    }
}
