export type UserType = 'ADMIN' | 'NORMAL';

export const USER_TYPE_TEXT_MAP: Record<UserType, string> = {
  ADMIN: '超级管理员',
  NORMAL: '平台用户'
};
