# Bulk Layer Editor

Simple tool to edit a selected blocklayer of the whole world.

## Usage

The primary use-case is to remove leftover blocks from the world after a plugin like [Waterlogging](https://github.com/platz1de/Waterlogging) has been uninstalled. <br>
Due to the way pocketmine interacts with the world, the blocks will stay without any way of removing them.

To use the tool, simply drop the phar into the plugins folder and restart the server. <br>
Then edit the config.yml to your liking and restart the server again. Worlds should now slowly be converted (This can take a while depending on the size of the world).