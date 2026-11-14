import type { DictCategoryVO } from '@/modules/dict/domain/DictCategoryVO'
import type { DictCategorySaveDTO } from '../domain/DictCategorySaveDTO'
import type { DictCategoryUpdateDTO } from '../domain/DictCategoryUpdateDTO'
import type { DictSaveDTO } from '../domain/DictSaveDTO'
import type { DictUpdateDTO } from '../domain/DictUpdateDTO'
import type { DictVO } from '../domain/DictVO'
import type { DictRepository } from '../repository/DictRepository'

export class DictService {
  constructor(private readonly repo: DictRepository) {}

  getById(id: string): Promise<DictVO> {
    return this.repo.getById(id)
  }

  save(dto: DictSaveDTO): Promise<boolean> {
    return this.repo.save(dto)
  }

  update(dto: DictUpdateDTO): Promise<boolean> {
    return this.repo.update(dto)
  }

  remove(id: string): Promise<boolean> {
    return this.repo.remove(id)
  }

  enable(id: string): Promise<boolean> {
    return this.repo.enable(id)
  }

  disable(id: string): Promise<boolean> {
    return this.repo.disable(id)
  }

  getCategories(enabledOnly = false): Promise<DictCategoryVO[]> {
    return this.repo.getCategories(enabledOnly)
  }

  getByCategoryId(categoryId: string): Promise<DictVO[]> {
    return this.repo.getByCategoryId(categoryId)
  }

  saveCategory(dto: DictCategorySaveDTO): Promise<boolean> {
    return this.repo.saveCategory(dto)
  }

  updateCategory(dto: DictCategoryUpdateDTO): Promise<boolean> {
    return this.repo.updateCategory(dto)
  }

  removeCategory(id: string): Promise<boolean> {
    return this.repo.removeCategory(id)
  }

  enableCategory(id: string): Promise<boolean> {
    return this.repo.enableCategory(id)
  }

  disableCategory(id: string): Promise<boolean> {
    return this.repo.disableCategory(id)
  }
}
