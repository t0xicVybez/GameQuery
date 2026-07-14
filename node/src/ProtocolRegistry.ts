import type { ProtocolInterface } from './protocol/ProtocolInterface.js';
import { Source } from './protocol/Source.js';
import { Minecraft } from './protocol/Minecraft.js';
import { Bedrock } from './protocol/Bedrock.js';
import { Palworld } from './protocol/Palworld.js';
import { FiveM } from './protocol/FiveM.js';
import { Quake2 } from './protocol/Quake2.js';
import { Quake3 } from './protocol/Quake3.js';
import { GameSpy1 } from './protocol/GameSpy1.js';
import { GameSpy2 } from './protocol/GameSpy2.js';
import { GameSpy3 } from './protocol/GameSpy3.js';
import { Unreal2 } from './protocol/Unreal2.js';
import { Doom3 } from './protocol/Doom3.js';
import { Ase } from './protocol/Ase.js';
import { Mumble } from './protocol/Mumble.js';
import { Frostbite } from './protocol/Frostbite.js';
import { AssettoCorsa } from './protocol/AssettoCorsa.js';
import { TeamSpeak3 } from './protocol/TeamSpeak3.js';
import { Terraria } from './protocol/Terraria.js';
import { Samp } from './protocol/Samp.js';

export type ProtocolFactory = () => ProtocolInterface;

/**
 * Looks up a ProtocolInterface instance by the short name used in addServer().
 * Add a protocol by implementing ProtocolInterface (AbstractProtocol gives a
 * head start) and registering it here or via GameQuery.registerProtocol().
 */
export class ProtocolRegistry {
  private factories = new Map<string, ProtocolFactory>([
    [Source.protocolName(), () => new Source()],
    ['source-players', () => new Source(true)],
    ['source-full', () => new Source(true, true)],
    [Minecraft.protocolName(), () => new Minecraft()],
    [Bedrock.protocolName(), () => new Bedrock()],
    ['minecraft-bedrock', () => new Bedrock()],
    [Palworld.protocolName(), () => new Palworld()],
    ['palworld-info', () => new Palworld(false)],
    [FiveM.protocolName(), () => new FiveM()],
    ['fivem-info', () => new FiveM(false)],
    [Quake2.protocolName(), () => new Quake2()],
    [Quake3.protocolName(), () => new Quake3()],
    [GameSpy1.protocolName(), () => new GameSpy1()],
    [GameSpy2.protocolName(), () => new GameSpy2()],
    [GameSpy3.protocolName(), () => new GameSpy3()],
    [Unreal2.protocolName(), () => new Unreal2()],
    ['unreal2-info', () => new Unreal2(false)],
    [Doom3.protocolName(), () => new Doom3()],
    [Ase.protocolName(), () => new Ase()],
    [Mumble.protocolName(), () => new Mumble()],
    [Frostbite.protocolName(), () => new Frostbite()],
    [AssettoCorsa.protocolName(), () => new AssettoCorsa()],
    [TeamSpeak3.protocolName(), () => new TeamSpeak3()],
    [Terraria.protocolName(), () => new Terraria()],
    [Samp.protocolName(), () => new Samp()],
    ['samp-info', () => new Samp(false)],
    ['openmp', () => new Samp()],
  ]);

  register(name: string, factory: ProtocolFactory): void {
    this.factories.set(name, factory);
  }

  has(name: string): boolean {
    return this.factories.has(name);
  }

  get(name: string): ProtocolInterface {
    const factory = this.factories.get(name);
    if (!factory) {
      throw new Error(`Unknown protocol '${name}'. Register it with registerProtocol() first.`);
    }
    return factory();
  }
}
