import { constants } from 'node:fs';
import { open, realpath } from 'node:fs/promises';
import { homedir } from 'node:os';
import { basename, extname, isAbsolute, join, relative, sep } from 'node:path';
import { createInterface } from 'node:readline';

const maxMediaBytes = 8 * 1024 * 1024;
const projectProfileGuard = '<?php exit; __halt_compiler();\n';
const environments = ['local', 'development', 'staging', 'production'];
const mimeTypes = {
    '.avif': 'image/avif',
    '.gif': 'image/gif',
    '.jpeg': 'image/jpeg',
    '.jpg': 'image/jpeg',
    '.mp3': 'audio/mpeg',
    '.mp4': 'video/mp4',
    '.pdf': 'application/pdf',
    '.png': 'image/png',
    '.webp': 'image/webp',
};

async function loadProfile() {
    const path = process.env.WP_MCP_CONFIG || join(homedir(), '.config', 'wp-mcp', 'profiles.json');
    const file = await open(path, 'r');
    let config;
    let profileFile;
    try {
        profileFile = await file.stat();
        if (!profileFile.isFile() || (process.platform !== 'win32' && (profileFile.mode & 0o077))) {
            throw new Error('The profiles file must be private (chmod 600).');
        }
        const contents = await file.readFile('utf8');
        if (path.endsWith('.php') && !contents.startsWith(projectProfileGuard)) {
            throw new Error('The project profiles file has an invalid PHP guard.');
        }
        const payload = contents.startsWith(projectProfileGuard) ? contents.slice(projectProfileGuard.length) : contents;
        config = JSON.parse(payload);
    } finally {
        await file.close();
    }

    const name = process.argv[2] || config.defaultProfile;
    if (typeof name !== 'string' || !Object.hasOwn(config.profiles ?? {}, name)) {
        throw new Error('Select an existing WordPress profile.');
    }
    const profile = config.profiles[name];
    if (!profile || typeof profile !== 'object' || Array.isArray(profile)) {
        throw new Error('The selected WordPress profile must be an object.');
    }
    if (Object.hasOwn(profile, 'disabled') && typeof profile.disabled !== 'boolean') {
        throw new Error('The disabled property must be true or false.');
    }
    if (profile.disabled) {
        throw new Error('The selected WordPress profile is disabled.');
    }
    const { endpoint, environment, username, applicationPassword } = profile;
    if (
        !environments.includes(environment) ||
        typeof username !== 'string' || !username || username.includes(':') ||
        typeof applicationPassword !== 'string' || !applicationPassword.trim()
    ) {
        throw new Error('The selected profile has invalid WordPress credentials or environment.');
    }

    let url;
    try {
        url = new URL(endpoint);
    } catch {
        throw new Error('The selected profile has an invalid endpoint URL.');
    }
    const localHttp = url.protocol === 'http:' && environment === 'local' &&
        ['localhost', '127.0.0.1', '[::1]'].includes(url.hostname);
    if ((!localHttp && url.protocol !== 'https:') || url.username || url.password || url.hash) {
        throw new Error('Use an HTTPS endpoint, or loopback HTTP for the local environment.');
    }

    return { ...profile, url, profileFile };
}

async function prepareUpload(args, profile) {
    if (typeof args.file_path !== 'string' || !args.file_path || !profile.mediaDirectory) {
        throw new Error('Set mediaDirectory in this profile and select a file in that directory.');
    }
    if (!isAbsolute(profile.mediaDirectory)) {
        throw new Error('mediaDirectory must be an absolute path.');
    }
    const root = await realpath(profile.mediaDirectory);
    const filePath = await realpath(isAbsolute(args.file_path) ? args.file_path : join(root, args.file_path));
    const location = relative(root, filePath);
    if (!location || location === '..' || location.startsWith('..' + sep) || isAbsolute(location)) {
        throw new Error('The file must be inside the configured mediaDirectory.');
    }
    if (filePath.split(sep).includes('.wp-mcp')) {
        throw new Error('MCP profile files cannot be uploaded.');
    }

    const { file_path, ...upload } = args;
    upload.filename ??= basename(filePath);
    upload.mime_type ??= mimeTypes[extname(upload.filename).toLowerCase()];
    if (!upload.mime_type) {
        throw new Error('Please provide the MIME type for this file extension.');
    }
    const file = await open(filePath, constants.O_RDONLY | (constants.O_NOFOLLOW ?? 0) | (constants.O_NONBLOCK ?? 0));
    try {
        const info = await file.stat();
        if (info.dev === profile.profileFile.dev && info.ino === profile.profileFile.ino) {
            throw new Error('MCP profile files cannot be uploaded.');
        }
        if (!info.isFile() || !info.size || info.size > maxMediaBytes) {
            throw new Error('The upload must be a nonempty regular file of at most 8 MiB.');
        }
        const bytes = Buffer.alloc(info.size);
        const { bytesRead } = await file.read(bytes, 0, bytes.length, 0);
        if (bytesRead !== bytes.length || filePath !== await realpath(filePath)) {
            throw new Error('The selected file changed during the upload.');
        }
        upload.base64 = bytes.toString('base64');
    } finally {
        await file.close();
    }
    return upload;
}

function respond(message) {
    process.stdout.write(JSON.stringify(message) + '\n');
}

function rpcError(id, code, message) {
    respond({ jsonrpc: '2.0', id, error: { code, message } });
}

async function handle(line, profile) {
    let message;
    try {
        message = JSON.parse(line);
    } catch {
        rpcError(null, -32700, 'Invalid JSON.');
        return;
    }
    if (message?.jsonrpc !== '2.0' || typeof message.method !== 'string') {
        rpcError(null, -32600, 'Invalid request.');
        return;
    }

    const { id, method, params } = message;
    if (id === undefined) {
        return;
    }
    if (!Number.isInteger(id) && typeof id !== 'string') {
        rpcError(null, -32600, 'Invalid request ID.');
        return;
    }

    if (method === 'tools/call' && params?.name === 'wordpress_upload_media' &&
        Object.hasOwn(params.arguments ?? {}, 'file_path')) {
        try {
            params.arguments = await prepareUpload(params.arguments, profile);
        } catch (error) {
            const reason = error.code ? 'The selected file could not be opened.' : error.message;
            respond({ jsonrpc: '2.0', id, result: { isError: true, content: [{ type: 'text', text: reason }] } });
            return;
        }
    }

    try {
        const auth = Buffer.from(profile.username + ':' + profile.applicationPassword, 'utf8').toString('base64');
        const response = await fetch(profile.url, {
            method: 'POST',
            headers: {
                'Accept': 'application/json, text/event-stream',
                'Authorization': 'Basic ' + auth,
                'Content-Type': 'application/json',
                'X-WP-MCP-Environment': profile.environment,
            },
            body: JSON.stringify(message),
            redirect: 'error',
            signal: AbortSignal.timeout(120_000),
        });
        const data = await response.json().catch(() => null);
        if (!response.ok) {
            const reason = typeof data?.message === 'string' ? ' ' + data.message : '';
            rpcError(id, -32000, 'WordPress returned HTTP ' + response.status + '.' + reason);
        } else if (data?.jsonrpc === '2.0' && data.id === id &&
            (Object.hasOwn(data, 'result') || Object.hasOwn(data, 'error'))) {
            respond(data);
        } else {
            rpcError(id, -32000, 'WordPress did not return a matching JSON-RPC response.');
        }
    } catch {
        rpcError(id, -32000, 'The request to WordPress failed. Verify connectivity and check site state before retrying a write.');
    }
}

try {
    const profile = await loadProfile();
    const input = createInterface({ input: process.stdin, crlfDelay: Infinity });
    input.on('line', (line) => {
        void handle(line, profile);
    });
} catch (error) {
    const reason = error instanceof SyntaxError ? 'The profiles file is not valid JSON.' : error.message;
    process.stderr.write('WordPress MCP: ' + reason + '\n');
    process.exitCode = 1;
}
