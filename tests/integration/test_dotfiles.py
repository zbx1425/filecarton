from rig.client import CartonClient, json_body
from rig.scenario import PASSWORD, dotfiles


def test_dotfiles_are_hidden_and_not_created(stack, tmp_path_factory, tools):
    work = tmp_path_factory.mktemp("dots")
    scenario = dotfiles(work, tools.php_exe)
    url = stack.activate(scenario)
    client = CartonClient(url)
    try:
        assert client.login("alice", PASSWORD).status_code == 200
        listed = json_body(client.api("list", csrf=False))
        names = [item["name"] for item in listed["data"]["files"]]
        assert ".hidden" not in names
        assert "hello.txt" in names
        created = client.api(
            "create",
            method="POST",
            json_body={"path": "", "name": ".secret", "type": "file"},
        )
        assert created.status_code == 403, created.text
        assert not (scenario.alice_root / ".secret").exists()
    finally:
        client.close()
