import type { UserType } from '@/modules/auth/domain/UserType';

export interface CurrentUser {
  memberId: string;
  userName: string;
  headImg?: string;
  gender?: 'MAN' | 'WOMAN' | null;
  profileBio?: string;
  userType?: UserType;
  email?: string;
  mobile?: string;
}
