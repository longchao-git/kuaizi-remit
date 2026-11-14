import type { CurrentUser } from '@/modules/profile/domain/CurrentUser';

export type LoginResponse = {
  requiresMobileBinding: true;
  bindingToken: string;
  token?: never;
  currentUser?: never;
} | {
  requiresMobileBinding?: false;
  token: string;
  currentUser: CurrentUser;
};
