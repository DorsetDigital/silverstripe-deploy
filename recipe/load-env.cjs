'use strict';

const fs = require('fs');
const { spawnSync } = require('child_process');

// Parse the key=value syntax used by project .env files without evaluating shell code.
function parseEnv(content) {
    const values = {};
    const lines = content.replace(/^\uFEFF/, '').split(/\r?\n/);
    for (const line of lines) {
        const match = line.match(/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/);
        if (!match) continue;
        const key = match[1];
        let value = match[2].trim();
        if (['"', "'", '`'].includes(value[0])) {
            const quote = value[0];
            const end = value.lastIndexOf(quote);
            if (end <= 0 || !/^\s*(?:#.*)?$/.test(value.slice(end + 1))) {
                throw new Error('Invalid quoted .env value for ' + key);
            }
            value = value.slice(1, end);
            if (quote === '"') {
                value = value.replace(/\\n/g, '\n').replace(/\\r/g, '\r');
            }
        } else {
            value = value.replace(/\s*#.*$/, '').trim();
        }
        values[key] = value;
    }
    return values;
}

if (require.main === module) {
    const command = process.argv[2];
    if (!command) {
        console.error('Missing asset build command');
        process.exit(1);
    }
    try {
        const environment = {
            ...process.env,
            ...parseEnv(fs.readFileSync('.env', 'utf8')),
        };
        const result = spawnSync(command, { shell: true, stdio: 'inherit', env: environment });
        if (result.error) throw result.error;
        process.exit(result.status === null ? 1 : result.status);
    } catch (error) {
        console.error('Asset build environment: ' + error.message);
        process.exit(1);
    }
}

module.exports = { parseEnv };
