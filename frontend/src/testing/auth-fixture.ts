import { TokenType } from '@models/token-type.enum';
import { LoginResponse } from '@models/auth';

/** Synthetic token for local guard/session tests only; never used against the real backend. */
export function loginFixture(seconds = 3600, suffix = 'signature'): LoginResponse {
    const exp = Math.floor(Date.now() / 1000) + seconds;
    const payload = btoa(JSON.stringify({ exp }))
        .replace(/=/g, '')
        .replace(/\+/g, '-')
        .replace(/\//g, '_');
    return {
        accessToken: `header.${payload}.${suffix}`,
        tokenType: TokenType.Bearer,
        expiresIn: seconds,
        user: { id: 1, username: 'fixture' },
    };
}
