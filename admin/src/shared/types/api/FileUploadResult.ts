/**
 * 文件上传单项结果
 */
export interface FileUploadResult {
  success: boolean;
  fileUrl?: string;
  fileName: string;
  errorMessage?: string | null;
}
