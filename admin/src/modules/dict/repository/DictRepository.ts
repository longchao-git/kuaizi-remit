import { del, get, post, put } from '@/request/http'
import type { DictCategoryVO } from '@/modules/dict/domain/DictCategoryVO'
import type { DictCategorySaveDTO } from '../domain/DictCategorySaveDTO'
import type { DictCategoryUpdateDTO } from '../domain/DictCategoryUpdateDTO'
import type { DictVO } from '../domain/DictVO'
import type { DictSaveDTO } from '../domain/DictSaveDTO'
import type { DictUpdateDTO } from '../domain/DictUpdateDTO'

export class DictRepository {
  private readonly basePath = '/dict'

  async getById(id: string): Promise<DictVO> {
    return await get<DictVO>(`${this.basePath}/${encodeURIComponent(id)}`)
  }

  async save(dto: DictSaveDTO): Promise<boolean> {
    return await post<boolean, DictSaveDTO>(this.basePath, dto)
  }

  async update(dto: DictUpdateDTO): Promise<boolean> {
    return await put<boolean, DictUpdateDTO>(this.basePath, dto)
  }

  async remove(id: string): Promise<boolean> {
    return await del<boolean>(`${this.basePath}/${encodeURIComponent(id)}`)
  }

  async enable(id: string): Promise<boolean> {
    return await put<boolean, undefined>(`${this.basePath}/${encodeURIComponent(id)}/enable`, undefined)
  }

  async disable(id: string): Promise<boolean> {
    return await put<boolean, undefined>(`${this.basePath}/${encodeURIComponent(id)}/disable`, undefined)
  }

  async getCategories(enabledOnly = false): Promise<DictCategoryVO[]> {
    return await get<DictCategoryVO[]>(`${this.basePath}/categories`, { enabledOnly })
  }

  async getByCategoryId(categoryId: string): Promise<DictVO[]> {
    return await get<DictVO[]>(`${this.basePath}/categories/${encodeURIComponent(categoryId)}/items`)
  }

  async saveCategory(dto: DictCategorySaveDTO): Promise<boolean> {
    return await post<boolean, DictCategorySaveDTO>(`${this.basePath}/categories`, dto)
  }

  async updateCategory(dto: DictCategoryUpdateDTO): Promise<boolean> {
    return await put<boolean, DictCategoryUpdateDTO>(`${this.basePath}/categories`, dto)
  }

  async removeCategory(id: string): Promise<boolean> {
    return await del<boolean>(`${this.basePath}/categories/${encodeURIComponent(id)}`)
  }

  async enableCategory(id: string): Promise<boolean> {
    return await put<boolean, undefined>(`${this.basePath}/categories/${encodeURIComponent(id)}/enable`, undefined)
  }

  async disableCategory(id: string): Promise<boolean> {
    return await put<boolean, undefined>(`${this.basePath}/categories/${encodeURIComponent(id)}/disable`, undefined)
  }
}
