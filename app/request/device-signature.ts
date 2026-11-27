// #ifdef APP-PLUS
import { publicKey, sign } from '@/uni_modules/remit-device-signing';
// #endif

export function deviceLoginFields(): { device_public_key?: string } {
  // #ifdef APP-PLUS
  if (import.meta.env.VITE_REMIT_DEVICE_SIGNING === 'true') {
    const key = publicKey();
    if (!key) throw new Error('无法准备设备安全凭据，请在支持的设备上重试');
    return { device_public_key: key };
  }
  // #endif
  return {};
}

export function deviceSignatureHeaders(url: string, method: string, body: string, bearer: string): Record<string, string> {
  // #ifdef APP-PLUS
  if (import.meta.env.VITE_REMIT_DEVICE_SIGNING === 'true' && bearer) {
    const path = url.replace(/^https?:\/\/[^/]+/, '');
    const endpoint = path.replace(/^\/api\//, '/');
    const protectedPost = method === 'POST' && ['/app/kyc/idv', '/app/kyc/profile', '/app/remittance/orders'].includes(endpoint);
    const protectedPut = method === 'PUT' && /^\/app\/remittance\/payout-account-drafts\/[a-fA-F0-9-]{36}$/.test(endpoint);
    if (protectedPost || protectedPut) {
      const [time, nonce, signature] = sign(method, path, body, bearer).split('|');
      if (!signature || !time || !nonce) throw new Error('设备安全验证暂不可用，请重新登录后重试');
      return { 'X-Remit-Time': time, 'X-Remit-Nonce': nonce, 'X-Remit-Signature': signature };
    }
  }
  // #endif
  return {};
}
