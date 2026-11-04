import { TokenType } from '@models/token-type.enum';
export interface AuthUser {
    id: number;
    username: string;
}

export interface LoginRequest {
    username: string;
    password: string;
}

export interface LoginResponse {
    accessToken: string;
    tokenType: TokenType.Bearer;
    expiresIn: number;
    user: AuthUser;
}
