# Pull Request

## Liên kết Issue
Closes #(số issue) — PR không có issue link sẽ bị yêu cầu bổ sung.

## Loại thay đổi (đánh dấu `x`)
- [ ] `feature`: tính năng mới → base `develop`
- [ ] `bugfix`: sửa lỗi → base `develop`
- [ ] `hotfix`: sửa lỗi production → base `master` (+ back-merge `develop`)
- [ ] `release`: gộp `develop` → `master`
- [ ] `chore`: infra/CI/docs, không đổi behavior

## Mô tả
...

## Checklist
- [ ] Base branch đúng (`develop`, trừ `hotfix`/`release` → `master`)
- [ ] Tên nhánh đúng quy ước (`feature/*`, `bugfix/*`, `hotfix/*`, `release/*`)
- [ ] Commit message kiểu conventional (`feat:`, `fix:`, `chore:`, ...)
- [ ] `composer check` (phpcs PSR-12 + phpstan level 1) pass
- [ ] `vendor/bin/phpunit --testsuite=Unit` pass
- [ ] Nếu đổi pipeline chatbot: có unit test tương ứng (corpus gate thôi chưa đủ)
- [ ] Nếu thêm migration: chạy sau migration gate, không đụng schema cũ
- [ ] Không commit `.env`, secrets, reports, model cache, DB artifacts
- [ ] Không lộ provider ID (Stylitics/FindMine) ra API/UI
- [ ] Đã tự review diff (`git status`, `git diff`, file nhạy cảm?)

## Bằng chứng test
Dán lệnh + kết quả (log ngắn, trace_id Langfuse nếu là chatbot):
```
...
```

## Ảnh hưởng / rủi ro rollout
...
