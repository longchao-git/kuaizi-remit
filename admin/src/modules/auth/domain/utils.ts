/**
 * 验证用户名格式
 * @param username 用户名
 * @returns 是否有效
 */
export function validateUsername(username: string): boolean {
  return Boolean(username && username.trim().length > 0);
}

/**
 * 验证密码格式
 * @param password 密码
 * @returns 是否有效
 */
export function validatePassword(password: string): boolean {
  return Boolean(password && password.trim().length > 0);
}

/**
 * 验证用户协议是否同意
 * @param agreed 是否同意
 * @returns 是否有效
 */
export function validateAgreement(agreed: boolean): boolean {
  return agreed === true;
}
