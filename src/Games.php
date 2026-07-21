<?php

declare(strict_types=1);

namespace GameQuery;

/**
 * Maps a friendly game id to the protocol + default query port, so callers can
 * query by game ("rust") instead of knowing it's A2S on a particular port. Ports
 * are the common defaults; a server on a non-standard port still needs it passed
 * explicitly. A few games need extra options (Palworld/Terraria credentials).
 *
 * Kept 1:1 with the Node port's Games map (enforced by tests/parity-check.sh).
 */
final class Games
{
    /** @var array<string, array{protocol: string, port: int, name: string}> */
    public const GAMES = [
        // Source / A2S
        'cs2' => ['protocol' => 'source', 'port' => 27015, 'name' => 'Counter-Strike 2'],
        'csgo' => ['protocol' => 'source', 'port' => 27015, 'name' => 'CS:GO'],
        'css' => ['protocol' => 'source', 'port' => 27015, 'name' => 'Counter-Strike: Source'],
        'tf2' => ['protocol' => 'source', 'port' => 27015, 'name' => 'Team Fortress 2'],
        'gmod' => ['protocol' => 'source', 'port' => 27015, 'name' => "Garry's Mod"],
        'rust' => ['protocol' => 'source', 'port' => 28015, 'name' => 'Rust'],
        'ark' => ['protocol' => 'source', 'port' => 27015, 'name' => 'ARK: Survival Evolved'],
        'arksa' => ['protocol' => 'source', 'port' => 27015, 'name' => 'ARK: Survival Ascended'],
        'valheim' => ['protocol' => 'source', 'port' => 2457, 'name' => 'Valheim'],
        'dayz' => ['protocol' => 'source', 'port' => 27016, 'name' => 'DayZ'],
        '7dtd' => ['protocol' => 'source', 'port' => 26900, 'name' => '7 Days to Die'],
        'projectzomboid' => ['protocol' => 'source', 'port' => 16261, 'name' => 'Project Zomboid'],
        'conanexiles' => ['protocol' => 'source', 'port' => 27015, 'name' => 'Conan Exiles'],
        'unturned' => ['protocol' => 'source', 'port' => 27015, 'name' => 'Unturned'],
        'vrising' => ['protocol' => 'source', 'port' => 9876, 'name' => 'V Rising'],
        'spaceengineers' => ['protocol' => 'source', 'port' => 27016, 'name' => 'Space Engineers'],
        'insurgency' => ['protocol' => 'source', 'port' => 27131, 'name' => 'Insurgency: Sandstorm'],
        'squad' => ['protocol' => 'source', 'port' => 27165, 'name' => 'Squad'],
        'hll' => ['protocol' => 'source', 'port' => 26420, 'name' => 'Hell Let Loose'],
        'mordhau' => ['protocol' => 'source', 'port' => 7777, 'name' => 'Mordhau'],
        'scum' => ['protocol' => 'source', 'port' => 7042, 'name' => 'SCUM'],
        'barotrauma' => ['protocol' => 'source', 'port' => 27015, 'name' => 'Barotrauma'],
        'killingfloor2' => ['protocol' => 'source', 'port' => 27015, 'name' => 'Killing Floor 2'],
        'l4d2' => ['protocol' => 'source', 'port' => 27015, 'name' => 'Left 4 Dead 2'],
        'blackmesa' => ['protocol' => 'source', 'port' => 27015, 'name' => 'Black Mesa'],
        'theforest' => ['protocol' => 'source', 'port' => 27016, 'name' => 'The Forest'],
        'arma3' => ['protocol' => 'source', 'port' => 2303, 'name' => 'Arma 3'], // A2S query port = game port + 1
        'avorion' => ['protocol' => 'source', 'port' => 27000, 'name' => 'Avorion'],
        'empyrion' => ['protocol' => 'source', 'port' => 30000, 'name' => 'Empyrion - Galactic Survival'],
        'groundbranch' => ['protocol' => 'source', 'port' => 27015, 'name' => 'Ground Branch'],
        'hurtworld' => ['protocol' => 'source', 'port' => 12871, 'name' => 'Hurtworld'],
        'miscreated' => ['protocol' => 'source', 'port' => 64090, 'name' => 'Miscreated'],
        'pavlovvr' => ['protocol' => 'source', 'port' => 7777, 'name' => 'Pavlov VR'],
        'postscriptum' => ['protocol' => 'source', 'port' => 10037, 'name' => 'Post Scriptum'],
        'stationeers' => ['protocol' => 'source', 'port' => 27500, 'name' => 'Stationeers'],
        'wreckfest' => ['protocol' => 'source', 'port' => 27015, 'name' => 'Wreckfest'],

        // Minecraft
        'minecraft' => ['protocol' => 'minecraft', 'port' => 25565, 'name' => 'Minecraft: Java Edition'],
        'minecraftbedrock' => ['protocol' => 'bedrock', 'port' => 19132, 'name' => 'Minecraft: Bedrock Edition'],

        // HTTP / REST (some need options)
        'fivem' => ['protocol' => 'fivem', 'port' => 30120, 'name' => 'FiveM'],
        'palworld' => ['protocol' => 'palworld', 'port' => 8212, 'name' => 'Palworld'], // options.password
        'terraria' => ['protocol' => 'terraria', 'port' => 7878, 'name' => 'Terraria (TShock)'], // options.token
        'assettocorsa' => ['protocol' => 'assettocorsa', 'port' => 8081, 'name' => 'Assetto Corsa'],

        // Other engines
        'satisfactory' => ['protocol' => 'satisfactory', 'port' => 7777, 'name' => 'Satisfactory'],
        'samp' => ['protocol' => 'samp', 'port' => 7777, 'name' => 'SA-MP'],
        'openmp' => ['protocol' => 'samp', 'port' => 7777, 'name' => 'open.mp'],
        'mtasa' => ['protocol' => 'ase', 'port' => 22126, 'name' => 'Multi Theft Auto'],
        'teamspeak3' => ['protocol' => 'teamspeak3', 'port' => 10011, 'name' => 'TeamSpeak 3'],
        'mumble' => ['protocol' => 'mumble', 'port' => 64738, 'name' => 'Mumble'],
        'quake3' => ['protocol' => 'quake3', 'port' => 27960, 'name' => 'Quake III Arena'],
        'cod4' => ['protocol' => 'quake3', 'port' => 28960, 'name' => 'Call of Duty 4'],
        'doom3' => ['protocol' => 'doom3', 'port' => 27666, 'name' => 'Doom 3'],
        'ut2004' => ['protocol' => 'unreal2', 'port' => 7778, 'name' => 'Unreal Tournament 2004'],
        'bf1942' => ['protocol' => 'gamespy2', 'port' => 23000, 'name' => 'Battlefield 1942'],
    ];

    /**
     * Look up a game's query wiring by id (case-insensitive), or null if unknown.
     *
     * @return array{protocol: string, port: int, name: string}|null
     */
    public static function info(string $game): ?array
    {
        return self::GAMES[strtolower($game)] ?? null;
    }
}
