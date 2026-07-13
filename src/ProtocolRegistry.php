<?php

declare(strict_types=1);

namespace GameQuery;

use GameQuery\Exception\GameQueryException;
use GameQuery\Protocol\Ase;
use GameQuery\Protocol\AssettoCorsa;
use GameQuery\Protocol\Bedrock;
use GameQuery\Protocol\Doom3;
use GameQuery\Protocol\Mumble;
use GameQuery\Protocol\FiveM;
use GameQuery\Protocol\Frostbite;
use GameQuery\Protocol\GameSpy1;
use GameQuery\Protocol\GameSpy2;
use GameQuery\Protocol\GameSpy3;
use GameQuery\Protocol\Minecraft;
use GameQuery\Protocol\Palworld;
use GameQuery\Protocol\ProtocolInterface;
use GameQuery\Protocol\Quake2;
use GameQuery\Protocol\Quake3;
use GameQuery\Protocol\Source;
use GameQuery\Protocol\TeamSpeak3;
use GameQuery\Protocol\Terraria;
use GameQuery\Protocol\Unreal2;

/**
 * Looks up a ProtocolInterface instance by the short name used in
 * addServer(), e.g. 'source' or 'minecraft'.
 *
 * To add support for another game protocol: write a class implementing
 * ProtocolInterface (AbstractProtocol gives you a head start), then either
 * call GameQuery::registerProtocol() at runtime or add a factory entry
 * below if it's going in the library itself.
 */
final class ProtocolRegistry
{
    /** @var array<string, callable(): ProtocolInterface> */
    private array $factories;

    public function __construct()
    {
        $this->factories = [
            Source::name() => static fn () => new Source(),
            'source-players' => static fn () => new Source(includePlayers: true),
            'source-full' => static fn () => new Source(includePlayers: true, includeRules: true),
            Minecraft::name() => static fn () => new Minecraft(),
            Bedrock::name() => static fn () => new Bedrock(),
            'minecraft-bedrock' => static fn () => new Bedrock(),
            Palworld::name() => static fn () => new Palworld(),
            'palworld-info' => static fn () => new Palworld(includePlayers: false),
            FiveM::name() => static fn () => new FiveM(),
            'fivem-info' => static fn () => new FiveM(includePlayers: false),
            Quake2::name() => static fn () => new Quake2(),
            Quake3::name() => static fn () => new Quake3(),
            GameSpy1::name() => static fn () => new GameSpy1(),
            GameSpy2::name() => static fn () => new GameSpy2(),
            GameSpy3::name() => static fn () => new GameSpy3(),
            Unreal2::name() => static fn () => new Unreal2(),
            'unreal2-info' => static fn () => new Unreal2(includePlayers: false),
            Doom3::name() => static fn () => new Doom3(),
            Ase::name() => static fn () => new Ase(),
            Mumble::name() => static fn () => new Mumble(),
            Frostbite::name() => static fn () => new Frostbite(),
            AssettoCorsa::name() => static fn () => new AssettoCorsa(),
            TeamSpeak3::name() => static fn () => new TeamSpeak3(),
            Terraria::name() => static fn () => new Terraria(),
        ];
    }

    public function register(string $name, callable $factory): void
    {
        $this->factories[$name] = $factory;
    }

    public function get(string $name): ProtocolInterface
    {
        if (!isset($this->factories[$name])) {
            throw new GameQueryException(
                "Unknown protocol '{$name}'. Register it with GameQuery::registerProtocol() first."
            );
        }

        return ($this->factories[$name])();
    }

    public function has(string $name): bool
    {
        return isset($this->factories[$name]);
    }
}
