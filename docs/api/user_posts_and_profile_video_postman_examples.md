# User Posts and Member Profile Video API Examples

## Fetch a user's posts

```http
GET /api/v1/users/{user_id}/posts?page=1&per_page=10
Authorization: Bearer {token}
Accept: application/json
```

Example:

```http
GET /api/v1/users/5066c9ed-39f6-43ad-bd33-adf78a8f0cbf/posts?page=1&per_page=10
```

Expected success response shape:

```json
{
  "success": true,
  "message": "User posts fetched successfully.",
  "data": {
    "user_id": "5066c9ed-39f6-43ad-bd33-adf78a8f0cbf",
    "total": 20,
    "current_page": 1,
    "per_page": 10,
    "last_page": 2,
    "items": []
  }
}
```

Notes:

- `per_page` defaults to `10` and is capped at `50`.
- Posts are ordered newest first.
- Invalid or unknown users return `404`.

## Confirm member profile photo URL

```http
GET /api/v1/members
Authorization: Bearer {token}
Accept: application/json
```

Each member item keeps `profile_photo_id` and also includes `profile_photo_url` built with `/api/v1/files/{id}`:

```json
{
  "profile_photo_id": "019d6783-7042-7111-aa4e-a197248ec0ec",
  "profile_photo_url": "https://peersunity.com/api/v1/files/019d6783-7042-7111-aa4e-a197248ec0ec"
}
```

If the member has no profile photo, `profile_photo_url` is `null`.

## Confirm member profile video fields

```http
GET /api/v1/members
Authorization: Bearer {token}
Accept: application/json
```

Each member item keeps existing profile video fields and sets `profile_video_url` from the first item in the `media` JSON column:

```json
{
  "media": [
    {
      "id": "019e1c11-a32e-709e-9694-a887466c6cfc",
      "url": "https://peersunity.com/api/v1/files/019e1c11-a32e-709e-9694-a887466c6cfc"
    }
  ],
  "profile_video_url": "https://peersunity.com/api/v1/files/019e1c11-a32e-709e-9694-a887466c6cfc"
}
```

If the member has no profile video:

```json
{
  "profile_video_id": null,
  "profile_video": null,
  "profile_video_url": null
}
```

## Open a profile video file

```http
GET /api/v1/files/{media_item_id}
Authorization: Bearer {token}
```
