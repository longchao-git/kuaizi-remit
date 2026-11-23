import { get, post, put } from '@/request/http'

export type AgreementKind = 'terms' | 'privacy'

export interface PublishedAgreement {
  version: string
  body: string
  published_at: string
}

export interface AgreementDraft {
  body: string
  revision: string
  updated_at: string
}

export interface AgreementEditor {
  document: PublishedAgreement | null
  draft: AgreementDraft | null
}

const endpoint = '/admin/remittance/agreements/'

export function readAgreementEditor(kind: AgreementKind): Promise<AgreementEditor> {
  return get<AgreementEditor>(endpoint + kind)
}

export function saveAgreementDraft(kind: AgreementKind, body: string, revision: string | null): Promise<AgreementDraft> {
  return put<AgreementDraft>(endpoint + kind + '/draft', { body, draft_revision: revision })
}

export function publishAgreement(kind: AgreementKind, body: string, revision: string | null): Promise<PublishedAgreement> {
  return post<PublishedAgreement>(endpoint + kind, { body, draft_revision: revision })
}
