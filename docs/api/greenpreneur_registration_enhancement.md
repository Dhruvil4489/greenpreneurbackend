# Greenpreneur Registration API Enhancement

This documentation details the new fields added to the user registration API (`POST /api/v1/auth/register`) for the Greenpreneur platform.

All fields are fully compatible with existing mobile/web client requests. If the fields are completely omitted, they will default safely (e.g. `community_directory_listing` will default to `'No'`, and array fields will default to empty arrays).

## API Endpoint Details

### `POST /api/v1/auth/register`
- **Content-Type**: `application/json`
- **Accept**: `application/json`

---

## New Payload Validation Rules

| Field Name | Type | Options / Allowed Values | Validation Rules | Description |
| :--- | :--- | :--- | :--- | :--- |
| `website` | Text / URL | Any valid URL | `nullable\|url\|max:255` | The website of the registering user or business. |
| `sustainability_contribution` | Text | Any string | `nullable\|string` | Explanation of how the business contributes to sustainability. |
| `sustainability_areas` | JSON Array | *See options list below* | `nullable\|array` | Multi-select list of sustainability focus areas. |
| `greenpreneur_goals` | JSON Array | *See options list below* | `nullable\|array` | Multi-select list of goals the user wants to achieve. |
| `interests` | JSON Array | *See options list below* | `nullable\|array` | Multi-select list of opportunities/interests of the user. |
| `community_directory_listing` | String | `Yes`, `No` | `sometimes\|required\|in:Yes,No` | Whether the user agrees to be listed in the Community Directory. Defaults to `No` if omitted. |

### Option Lists

#### 1. Sustainability Areas (`sustainability_areas`)
- `Renewable Energy`
- `Waste Management`
- `Water Conservation`
- `Sustainable Agriculture`
- `Green Construction`
- `Circular Economy`
- `ESG Consulting`
- `Electric Mobility`
- `Carbon Reduction`
- `Recycling`
- `Climate Technology`
- `Sustainable Packaging`
- `Biodiversity`
- `Green Finance`
- `Other`

#### 2. Greenpreneur Goals (`greenpreneur_goals`)
- `Business Growth`
- `Partnerships`
- `Investors`
- `Customers`
- `Government Connect`
- `Knowledge Sharing`
- `Technology Partners`
- `Global Expansion`
- `Sustainability Learning`

#### 3. Interests (`interests`)
- `Speaking Opportunities`
- `Panel Discussions`
- `Mentoring`
- `Exhibiting`
- `Sponsorship`
- `Investment Opportunities`
- `Greenpreneur Awards`
- `Coffee Table Book Feature`
- `Impact Story`

---

## Request & Response Samples

### 1. Fully Filled Request Payload

```json
{
  "first_name": "Green",
  "last_name": "Preneur",
  "email": "greenpreneur-full@example.com",
  "phone": "9999999999",
  "password": "password123",
  "password_confirmation": "password123",
  "website": "https://sustainability.com",
  "sustainability_contribution": "Our business uses 100% solar power and recycles 90% of waste.",
  "sustainability_areas": [
    "Renewable Energy",
    "Waste Management",
    "Recycling"
  ],
  "greenpreneur_goals": [
    "Business Growth",
    "Partnerships",
    "Investors"
  ],
  "interests": [
    "Speaking Opportunities",
    "Panel Discussions"
  ],
  "community_directory_listing": "Yes"
}
```

### 2. Fully Filled Response Payload (HTTP 201)

```json
{
  "success": true,
  "message": "Registration successful.",
  "data": {
    "token": "1|sanctum_token_plain_text",
    "user": {
      "id": "e4f3a2c5-1b2d-4c3e-b5a6-7d8e9f0a1b2c",
      "first_name": "Green",
      "last_name": "Preneur",
      "display_name": "Green Preneur",
      "email": "greenpreneur-full@example.com",
      "phone": "9999999999",
      "website": "https://sustainability.com",
      "sustainability_contribution": "Our business uses 100% solar power and recycles 90% of waste.",
      "sustainability_areas": [
        "Renewable Energy",
        "Waste Management",
        "Recycling"
      ],
      "greenpreneur_goals": [
        "Business Growth",
        "Partnerships",
        "Investors"
      ],
      "interests": [
        "Speaking Opportunities",
        "Panel Discussions"
      ],
      "community_directory_listing": "Yes",
      "status": "inactive",
      "membership_status": "free_trial_peer",
      "created_at": "2026-06-24T16:15:00.000000Z",
      "updated_at": "2026-06-24T16:15:00.000000Z"
    }
  }
}
```
