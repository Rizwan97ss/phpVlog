import { describe, expect, it } from 'vitest';
import { editorUrl, isLocalPath, isVendorPath, mapPath, type EditorSettings } from './editor';

const vscode: EditorSettings = { editor: 'vscode', serverPath: '', localPath: '' };

describe('editorUrl', () => {
    it('links to a file and line', () => {
        expect(editorUrl('/var/www/app/Order.php', 12, vscode)).toBe('vscode://file//var/www/app/Order.php:12');
        expect(editorUrl('/var/www/app/Order.php', 12, { ...vscode, editor: 'phpstorm' })).toBe(
            'phpstorm://open?file=/var/www/app/Order.php&line=12',
        );
    });

    it('links to Windows paths with forward slashes', () => {
        expect(editorUrl('C:\\Users\\amy\\app\\Order.php', 3, vscode)).toBe('vscode://file/C:/Users/amy/app/Order.php:3');
    });

    it('encodes characters that would change the link', () => {
        expect(editorUrl('/srv/my app/#1?.php', 1, vscode)).toBe('vscode://file//srv/my%20app/%231%3F.php:1');
    });

    it('opens the first line when the line is unknown', () => {
        expect(editorUrl('/srv/app.php', null, vscode)).toBe('vscode://file//srv/app.php:1');
    });

    it('does not link URLs, placeholders or unknown editors', () => {
        expect(editorUrl('https://app.test/build/app.js', 10, vscode)).toBeNull();
        expect(editorUrl('javascript:alert(1)', 1, vscode)).toBeNull();
        expect(editorUrl('[internal function]', null, vscode)).toBeNull();
        expect(editorUrl('/srv/app.php', 1, { ...vscode, editor: 'notepad' })).toBeNull();
    });

    it('uses the path mapping', () => {
        const settings = { editor: 'vscode', serverPath: '/var/www/html', localPath: '/Users/amy/app/' };

        expect(editorUrl('/var/www/html/app/Order.php', 7, settings)).toBe('vscode://file//Users/amy/app/app/Order.php:7');
    });
});

describe('mapPath', () => {
    const settings = { editor: 'vscode', serverPath: '/var/www/html', localPath: '/Users/amy/app' };

    it('replaces only a whole leading folder', () => {
        expect(mapPath('/var/www/html/index.php', settings)).toBe('/Users/amy/app/index.php');
        expect(mapPath('/var/www/html2/index.php', settings)).toBe('/var/www/html2/index.php');
        expect(mapPath('/srv/index.php', settings)).toBe('/srv/index.php');
    });
});

describe('paths', () => {
    it('tells local paths from URLs', () => {
        expect(isLocalPath('/var/www/app.php')).toBe(true);
        expect(isLocalPath('D:\\app\\x.php')).toBe(true);
        expect(isLocalPath('https://app.test/x.js')).toBe(false);
    });

    it('recognises dependency paths', () => {
        expect(isVendorPath('/var/www/vendor/laravel/framework/src/x.php')).toBe(true);
        expect(isVendorPath('C:\\app\\node_modules\\x.js')).toBe(true);
        expect(isVendorPath('/var/www/app/Http/Kernel.php')).toBe(false);
    });
});
