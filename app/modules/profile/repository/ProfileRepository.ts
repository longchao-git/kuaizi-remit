import request from '@/request/http';
import type { CurrentUser } from '../domain/CurrentUser';
import type { ProfileForm } from '../domain/ProfileForm';
import { BASE_URL } from '@/config/api';
import type { ApiResponse } from '@/shared/types/api';
import { sessionService } from '@/shared/session/application/SessionService';
import { rejectUnauthorized } from '@/request/http';

interface UploadResultItem {
  fileUrl?: string;
  success?: boolean;
  errorMessage?: string;
}

interface UploadResponseBody {
  results?: UploadResultItem[];
}

export class ProfileRepository {
  private readonly basePath = 'app/member';

  async getCurrentUser(): Promise<CurrentUser> {
    return await request.get<CurrentUser>(`${this.basePath}/current`);
  }

  async updateCurrentUser(profile: ProfileForm): Promise<CurrentUser> {
    return await request.put<CurrentUser>(`${this.basePath}/current`, profile);
  }

  async uploadAvatar(filePath: string): Promise<string> {
    const token = sessionService.getToken();
    return await new Promise<string>((resolve, reject) => {
      uni.uploadFile({
        url: BASE_URL + 'file/upload',
        filePath,
        name: 'files',
        header: token ? { Authorization: `Bearer ${token}` } : {},
        success: (result) => {
          try {
            const response = JSON.parse(result.data || '{}') as ApiResponse<UploadResponseBody>;
            const code = response ? Number(response.code) : NaN;
            const message = response.message || response.msg || '头像上传失败';

            if (result.statusCode === 401 || code === 401) {
              void rejectUnauthorized(message).catch(reject);
              return;
            }

            if (result.statusCode < 200 || result.statusCode >= 300) {
              reject(new Error(message));
              return;
            }

            const body = response && response.data !== undefined && response.data !== null ? response.data : response.body;
            const results = body && body.results ? body.results : [];
            const first = results.find((item) => item.success && item.fileUrl);
            if (code === 0 && first && first.fileUrl) {
              resolve(first.fileUrl);
              return;
            }
            reject(new Error((results[0] && results[0].errorMessage) || message));
          } catch (_error) {
            if (result.statusCode === 401) {
              void rejectUnauthorized().catch(reject);
              return;
            }
            reject(new Error('头像上传失败'));
          }
        },
        fail: () => reject(new Error('头像上传失败'))
      });
    });
  }
}
