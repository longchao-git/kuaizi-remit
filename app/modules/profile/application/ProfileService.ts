import { USER_TYPE_TEXT_MAP, type UserType } from '@/modules/auth/domain/UserType';
import type { CurrentUser } from '../domain/CurrentUser';
import type { ProfileForm } from '../domain/ProfileForm';
import { type SessionService, sessionService } from '@/shared/session/application/SessionService';
import { getErrorMessage } from '@/shared/utils/error';
import { ProfileRepository } from '../repository/ProfileRepository';

export class ProfileService {
  constructor(
    private readonly repo: ProfileRepository,
    private readonly session: SessionService
  ) {}

  getEmptyUser(): CurrentUser {
    return {
      memberId: '',
      userName: '',
      headImg: '',
      gender: null,
      profileBio: '',
      userType: undefined,
      email: '',
      mobile: ''
    };
  }

  getUserTypeText(userType?: UserType): string {
    if (!userType) {
      return '未登录';
    }

    return USER_TYPE_TEXT_MAP[userType] || '平台用户';
  }

  async loadCurrentUser(): Promise<CurrentUser> {
    try {
      const currentUser = await this.repo.getCurrentUser();
      this.session.setCurrentUser(currentUser);
      return currentUser;
    } catch (error) {
      throw new Error(getErrorMessage(error, '获取用户信息失败'));
    }
  }

  async updateCurrentUser(profile: ProfileForm): Promise<CurrentUser> {
    try {
      const currentUser = await this.repo.updateCurrentUser(profile);
      this.session.setCurrentUser(currentUser);
      return currentUser;
    } catch (error) {
      throw new Error(getErrorMessage(error, '保存资料失败'));
    }
  }

  async uploadAvatar(filePath: string): Promise<string> {
    try {
      return await this.repo.uploadAvatar(filePath);
    } catch (error) {
      throw new Error(getErrorMessage(error, '头像上传失败'));
    }
  }
}

export const profileService = new ProfileService(new ProfileRepository(), sessionService);
