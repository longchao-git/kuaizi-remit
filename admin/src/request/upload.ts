import type { FileUploadResult } from '@/shared/types/api/FileUploadResult'
import type { FileUploadResponse } from '@/shared/types/api/FileUploadResponse'
import { post } from './http'

export interface UploadOptions {
  onProgress?: (percentage: number) => void
}

const resolveUploadPercentage = (loaded: number, total?: number) => {
  if (!total || total <= 0) {
    return 0
  }
  return Math.max(0, Math.min(99, Math.round((loaded / total) * 100)))
}

const assertUploadSuccess = (result?: FileUploadResult) => {
  if (!result) {
    throw new Error('上传结果中未返回文件地址')
  }
  if (!result.success) {
    throw new Error(result.errorMessage || '上传失败')
  }
  if (!result.fileUrl) {
    throw new Error('上传结果中未返回文件地址')
  }
  return result.fileUrl
}

export const uploadFile = async (file: File, options?: UploadOptions): Promise<string> => {
  const formData = new FormData()
  formData.append('files', file)

  const response = await post<FileUploadResponse, FormData>('/file/upload', formData, {
    onUploadProgress: (progressEvent) => {
      options?.onProgress?.(resolveUploadPercentage(progressEvent.loaded, progressEvent.total ?? file.size))
    }
  })

  const fileUrl = assertUploadSuccess(response?.results?.[0])
  options?.onProgress?.(100)
  return fileUrl
}

export const uploadFiles = async (files: File[], options?: UploadOptions): Promise<string[]> => {
  const formData = new FormData()
  files.forEach(file => formData.append('files', file))

  const totalBytes = files.reduce((sum, file) => sum + (file.size || 0), 0)
  const response = await post<FileUploadResponse, FormData>('/file/upload', formData, {
    onUploadProgress: (progressEvent) => {
      options?.onProgress?.(resolveUploadPercentage(progressEvent.loaded, progressEvent.total ?? totalBytes))
    }
  })

  if (response?.results?.length) {
    const failedResult = response.results.find((result: FileUploadResult) => !result.success || !result.fileUrl)
    if (failedResult) {
      throw new Error(failedResult.errorMessage || '部分文件上传失败')
    }
    options?.onProgress?.(100)
    return response.results.map((result: FileUploadResult) => result.fileUrl as string)
  }
  throw new Error('批量上传结果中未返回文件地址')
}
