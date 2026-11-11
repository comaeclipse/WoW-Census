"""Register the WoWCensus Discord bot's slash commands (run once, and after changing them).

    $env:DISCORD_APP_ID = '<application id>'
    $env:DISCORD_BOT_TOKEN = '<bot token>'
    python -B tools/register-discord-commands.py            # global (can take up to an hour to show)
    python -B tools/register-discord-commands.py <guild id> # one server, shows at once

The commands are handled by laragon-php/src/discord-interactions.php at
https://wowcensus.io/discord/interactions (the app's Interactions Endpoint URL).
"""
import json
import os
import sys
import urllib.request

COMMANDS = [
    {
        "name": "submit",
        "type": 1,
        "description": "Send your WoWCensus.lua save file to WoWCensus",
        "integration_types": [0],  # installed to servers
        "contexts": [0, 1],  # usable in servers and in DMs with the bot
        "options": [
            {
                "name": "file",
                "type": 11,  # ATTACHMENT
                "description": "WoWCensus.lua from WTF\\Account\\<ACCOUNT>\\SavedVariables, or a .zip of it",
                "required": True,
            }
        ],
    }
]


def main() -> None:
    app_id = os.environ["DISCORD_APP_ID"]
    token = os.environ["DISCORD_BOT_TOKEN"]
    guild = sys.argv[1] if len(sys.argv) > 1 else None
    path = f"/applications/{app_id}" + (f"/guilds/{guild}" if guild else "") + "/commands"
    request = urllib.request.Request(
        "https://discord.com/api/v10" + path,
        data=json.dumps(COMMANDS).encode(),
        method="PUT",
        headers={
            "Authorization": f"Bot {token}",
            "Content-Type": "application/json",
            "User-Agent": "DiscordBot (https://wowcensus.io, 1.0)",
        },
    )
    with urllib.request.urlopen(request) as response:
        for command in json.load(response):
            print(f"registered /{command['name']} ({command['id']})")


if __name__ == "__main__":
    main()
