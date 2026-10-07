<?php

namespace FileCarton;

class NoLoginAuth implements AuthProvider {
    public function __construct(array $opts = []) {
    }

    public function id(): string { return 'nologin'; }

    public function label(): string { return 'No login'; }

    public function kind(): string { return 'implicit'; }

    /** Synthesize every request. Must not persist to session. */
    public function identity(): AuthIdentity {
        $list = Auth::staticUserList();
        if ($list === [] || !is_array($list[0]) || !isset($list[0]['id']) || !is_string($list[0]['id']) || $list[0]['id'] === '') {
            throw new \RuntimeException('NoLoginAuth requires FILECARTON_STATIC_USER_LIST[0].id', 500);
        }
        $id = $list[0]['id'];
        $display = $id;
        if (isset($list[0]['displayName']) && is_string($list[0]['displayName']) && $list[0]['displayName'] !== '') {
            $display = $list[0]['displayName'];
        }
        return new AuthIdentity($id, $display, $this->id());
    }
}
