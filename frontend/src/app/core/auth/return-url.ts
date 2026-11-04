/** Only application feature routes can be a post-login destination. */
export function safeReturnUrl(value: string | null): string {
    return value !== null &&
        /^\/(?:products|import)(?:\/[^/?#;()\\\s]+)?(?:\?[^#\\\r\n]*)?(?:#[^\\\r\n]*)?$/.test(value)
        ? value
        : '/products';
}
