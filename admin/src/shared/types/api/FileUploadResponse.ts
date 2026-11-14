import type { FileUploadResult } from './FileUploadResult'

/**
 * 文件上传响应
 */
export interface FileUploadResponse {
  results: FileUploadResult[];
  successCount: number;
  failCount: number;
}
