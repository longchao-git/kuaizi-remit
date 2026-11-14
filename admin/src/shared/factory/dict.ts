import { DictService } from '@/modules/dict/application/DictService'
import { DictRepository } from '@/modules/dict/repository/DictRepository'

const dictRepository = new DictRepository()
const dictService = new DictService(dictRepository)

export const useDictService = () => dictService
