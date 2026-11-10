'use strict';

const assert = require('assert');
const { parseEnv } = require('../recipe/load-env.cjs');

assert.deepStrictEqual(parseEnv([
    '# Site-specific Sass colours',
    'PRIMARY="#004225"',
    'SECONDARY="#ffffff"',
    'TERTIARY="#345DA3"',
    'DARK="#1B1D28"',
    'LIGHT="#EAEAEA"',
    'ROOT_FONT_SIZE="16px"',
    'NAME=Example # trailing comment',
    "SINGLE='a # literal hash'",
    'export ENABLED=true',
    '',
].join('\n')), {
    PRIMARY: '#004225',
    SECONDARY: '#ffffff',
    TERTIARY: '#345DA3',
    DARK: '#1B1D28',
    LIGHT: '#EAEAEA',
    ROOT_FONT_SIZE: '16px',
    NAME: 'Example',
    SINGLE: 'a # literal hash',
    ENABLED: 'true',
});

assert.throws(() => parseEnv('PRIMARY="#004225" unexpected'), /Invalid quoted/);
console.log('Asset build .env parser tests passed');
