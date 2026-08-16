ALTER TABLE users
  ADD COLUMN IF NOT EXISTS brewery_festival_started_at INT(10) UNSIGNED NOT NULL DEFAULT 0 AFTER cp_prod,
  ADD COLUMN IF NOT EXISTS brewery_festival_ends_at INT(10) UNSIGNED NOT NULL DEFAULT 0 AFTER brewery_festival_started_at;

UPDATE users u
JOIN (
  SELECT v.owner, v.festival AS festival_ends_at
  FROM vdata v
  JOIN fdata f ON f.kid=v.kid
  WHERE v.capital=1
    AND v.isWW=0
    AND v.festival > 0
    AND (SELECT COUNT(*) FROM vdata capitals WHERE capitals.owner=v.owner AND capitals.capital=1)=1
    AND (
      (f.f19t=35 AND f.f19>0) OR (f.f20t=35 AND f.f20>0)
      OR (f.f21t=35 AND f.f21>0) OR (f.f22t=35 AND f.f22>0)
      OR (f.f23t=35 AND f.f23>0) OR (f.f24t=35 AND f.f24>0)
      OR (f.f25t=35 AND f.f25>0) OR (f.f26t=35 AND f.f26>0)
      OR (f.f27t=35 AND f.f27>0) OR (f.f28t=35 AND f.f28>0)
      OR (f.f29t=35 AND f.f29>0) OR (f.f30t=35 AND f.f30>0)
      OR (f.f31t=35 AND f.f31>0) OR (f.f32t=35 AND f.f32>0)
      OR (f.f33t=35 AND f.f33>0) OR (f.f34t=35 AND f.f34>0)
      OR (f.f35t=35 AND f.f35>0) OR (f.f36t=35 AND f.f36>0)
      OR (f.f37t=35 AND f.f37>0) OR (f.f38t=35 AND f.f38>0)
      OR (f.f39t=35 AND f.f39>0) OR (f.f40t=35 AND f.f40>0)
    )
) legacy ON legacy.owner=u.id
SET u.brewery_festival_started_at=CASE
      WHEN legacy.festival_ends_at > u.brewery_festival_ends_at
        THEN GREATEST(0, legacy.festival_ends_at - 259200)
      ELSE u.brewery_festival_started_at
    END,
    u.brewery_festival_ends_at=GREATEST(u.brewery_festival_ends_at, legacy.festival_ends_at)
WHERE u.race=2;
