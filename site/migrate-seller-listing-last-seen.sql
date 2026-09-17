ALTER TABLE seller_listings ADD COLUMN last_seen INTEGER;

UPDATE seller_listings
SET last_seen = (
  SELECT sellers.last_seen
  FROM sellers
  WHERE sellers.game = seller_listings.game
    AND sellers.owner = seller_listings.owner
)
WHERE last_seen IS NULL;
